# Intégration mobile (iOS & Android)

Ce guide donne le code de signature prêt à l'emploi et le cycle de vie à implémenter dans les apps. Le protocole lui-même est décrit dans [SECURITE.md](SECURITE.md#2-protocole-de-signature).

## Cycle de vie

```
Premier lancement
  ├─ générer un UUID v4  ──────────────► stocker dans Keychain / Keystore (X-Device-Id)
  ├─ POST /devices/register (clé app) ─► stocker device_token + device_secret
  └─ corriger l'horloge avec server_time

Chaque requête
  └─ signer avec device_secret (en-têtes X-Device-*, X-Timestamp, X-Nonce, X-Signature)

Réponse 401…
  ├─ invalid_device            → réenregistrer l'appareil puis rejouer la requête une fois
  ├─ timestamp_out_of_window   → offset = X-Server-Time − heure locale, puis rejouer une fois
  ├─ token_expired             → POST /auth/refresh (un seul à la fois !) puis rejouer
  └─ invalid_refresh_token     → écran de connexion
```

Règles :

1. **Ne jamais** stocker `device_secret`, `device_token`, `refresh_token` dans `UserDefaults` / `SharedPreferences` en clair.
2. Le `X-Device-Id` doit survivre aux mises à jour de l'app : le générer une seule fois. (Sur iOS, ne pas utiliser `identifierForVendor`, qui peut changer ; sur Android, ne pas utiliser `ANDROID_ID`.)
3. Signer les **octets exacts** du corps envoyé : sérialiser le JSON une fois, signer ces octets, envoyer ces octets.
4. Construire les query strings avec l'encodage RFC 3986 (voir les helpers ci-dessous) : en particulier, `+` doit être envoyé `%2B`, sinon le serveur l'interprète comme un espace.
5. Pas de `/` final dans les chemins.

## Valider son implémentation

Avant tout appel réseau, faire passer ce test unitaire (mêmes valeurs que `tests/SignatureTest.php`) :

| Entrée | Valeur |
|---|---|
| secret | `test-secret` |
| méthode / chemin | `POST` `/v1/auth/login` |
| query | `b=2`, `a=hello world`, `a=1` |
| timestamp / nonce | `1700000000` / `abcdef0123456789` |
| device id | `3f2504e0-4f89-41d3-9a0c-0305e82c3301` |
| corps | `{"email":"a@b.fr","password":"secret123"}` |
| **query canonique attendue** | `a=1&a=hello%20world&b=2` |
| **signature attendue** | `92f9f6b9bfa2c96ac205992da06013833125905d831c28ad58add58200e14c04` |

---

## iOS — Swift (CryptoKit, iOS 13+)

```swift
import Foundation
import CryptoKit

/// Signature des requêtes myClermont. Voir docs/SECURITE.md.
enum RequestSigner {

    // MARK: Encodage canonique

    private static let unreserved = CharacterSet(
        charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~"
    )

    static func rfc3986(_ value: String) -> String {
        value.addingPercentEncoding(withAllowedCharacters: unreserved) ?? value
    }

    static func canonicalQuery(_ items: [URLQueryItem]) -> String {
        items
            .map { (rfc3986($0.name), rfc3986($0.value ?? "")) }
            .sorted { $0.0 != $1.0 ? $0.0 < $1.0 : $0.1 < $1.1 }
            .map { "\($0.0)=\($0.1)" }
            .joined(separator: "&")
    }

    /// Construit une URL dont la query est encodée exactement comme le serveur l'attend.
    static func makeURL(base: URL, path: String, query: [URLQueryItem] = []) -> URL {
        var comps = URLComponents(url: base.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        if !query.isEmpty {
            comps.percentEncodedQuery = query
                .map { "\(rfc3986($0.name))=\(rfc3986($0.value ?? ""))" }
                .joined(separator: "&")
        }
        return comps.url!
    }

    // MARK: Crypto

    static func sha256Hex(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    static func hmacHex(key: Data, message: String) -> String {
        let mac = HMAC<SHA256>.authenticationCode(for: Data(message.utf8), using: SymmetricKey(data: key))
        return mac.map { String(format: "%02x", $0) }.joined()
    }

    static func stringToSign(method: String, url: URL, timestamp: String,
                             nonce: String, deviceId: String, body: Data) -> String {
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        return [
            method.uppercased(),
            comps.percentEncodedPath,
            canonicalQuery(comps.queryItems ?? []),
            timestamp,
            nonce,
            deviceId,
            sha256Hex(body),
        ].joined(separator: "\n")
    }

    // MARK: Signature d'une URLRequest

    enum Credential {
        /// Requêtes normales : secret appareil (octets UTF-8 de la chaîne reçue)
        case device(token: String, secret: String)
        /// Enregistrement : clé application (décodée du base64)
        case app(keyId: String, keyBase64: String)
    }

    static func sign(_ request: inout URLRequest, deviceId: String,
                     credential: Credential, clockOffset: TimeInterval) {
        let timestamp = String(Int(Date().timeIntervalSince1970 + clockOffset))
        let nonce = UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased()
        let body = request.httpBody ?? Data()

        let key: Data
        switch credential {
        case let .device(token, secret):
            key = Data(secret.utf8)
            request.setValue(token, forHTTPHeaderField: "X-Device-Token")
        case let .app(keyId, keyBase64):
            key = Data(base64Encoded: keyBase64)!
            request.setValue(keyId, forHTTPHeaderField: "X-Key-Id")
        }

        let toSign = stringToSign(method: request.httpMethod ?? "GET", url: request.url!,
                                  timestamp: timestamp, nonce: nonce, deviceId: deviceId, body: body)

        request.setValue(deviceId, forHTTPHeaderField: "X-Device-Id")
        request.setValue(timestamp, forHTTPHeaderField: "X-Timestamp")
        request.setValue(nonce, forHTTPHeaderField: "X-Nonce")
        request.setValue(hmacHex(key: key, message: toSign), forHTTPHeaderField: "X-Signature")
    }
}
```

Test unitaire :

```swift
func testReferenceVector() {
    let url = RequestSigner.makeURL(base: URL(string: "https://x")!, path: "/v1/auth/login",
        query: [.init(name: "b", value: "2"), .init(name: "a", value: "hello world"), .init(name: "a", value: "1")])
    let body = Data(#"{"email":"a@b.fr","password":"secret123"}"#.utf8)
    let s = RequestSigner.stringToSign(method: "POST", url: url, timestamp: "1700000000",
        nonce: "abcdef0123456789", deviceId: "3f2504e0-4f89-41d3-9a0c-0305e82c3301", body: body)
    XCTAssertEqual(RequestSigner.hmacHex(key: Data("test-secret".utf8), message: s),
                   "92f9f6b9bfa2c96ac205992da06013833125905d831c28ad58add58200e14c04")
}
```

Stockage : utiliser le Keychain avec `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` (le secret ne doit pas être restauré sur un autre appareil via une sauvegarde iCloud).

Le `X-Device-Id` envoyé par iOS (`UUID().uuidString`) est en majuscules : c'est accepté, la signature utilise simplement la valeur telle qu'envoyée.

---

## Android — Kotlin (OkHttp)

```kotlin
import okhttp3.Interceptor
import okhttp3.Request
import okhttp3.Response
import okio.Buffer
import java.net.URLEncoder
import java.security.MessageDigest
import java.util.UUID
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

object RequestSigner {

    fun rfc3986(value: String): String =
        URLEncoder.encode(value, "UTF-8")
            .replace("+", "%20")
            .replace("*", "%2A")
            .replace("%7E", "~")

    fun canonicalQuery(pairs: List<Pair<String, String>>): String =
        pairs.map { rfc3986(it.first) to rfc3986(it.second) }
            .sortedWith(compareBy({ it.first }, { it.second }))
            .joinToString("&") { "${it.first}=${it.second}" }

    fun sha256Hex(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).toHex()

    fun hmacHex(key: ByteArray, message: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key, "HmacSHA256"))
        return mac.doFinal(message.toByteArray(Charsets.UTF_8)).toHex()
    }

    fun stringToSign(method: String, encodedPath: String, query: List<Pair<String, String>>,
                     timestamp: String, nonce: String, deviceId: String, body: ByteArray): String =
        listOf(method.uppercase(), encodedPath, canonicalQuery(query),
               timestamp, nonce, deviceId, sha256Hex(body)).joinToString("\n")

    private fun ByteArray.toHex() = joinToString("") { "%02x".format(it) }
}

/** Identifiants stockés de façon chiffrée (EncryptedSharedPreferences / Keystore). */
interface DeviceCredentialStore {
    val deviceId: String
    val deviceToken: String?
    val deviceSecret: String?
    var clockOffsetSeconds: Long
}

/**
 * Signe toutes les requêtes avec les identifiants de l'appareil.
 * Pour l'enregistrement, utiliser une variante qui envoie X-Key-Id et signe avec
 * la clé application décodée du base64 (Base64.decode(key, Base64.NO_WRAP)).
 */
class SignatureInterceptor(private val store: DeviceCredentialStore) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val original = chain.request()
        val token = store.deviceToken ?: return chain.proceed(original)
        val secret = store.deviceSecret ?: return chain.proceed(original)

        val response = chain.proceed(sign(original, token, secret))

        // Horloge décalée : on corrige et on rejoue une seule fois
        if (response.code == 401 && response.peekBody(512).string().contains("timestamp_out_of_window")) {
            response.header("X-Server-Time")?.toLongOrNull()?.let { serverTime ->
                store.clockOffsetSeconds = serverTime - System.currentTimeMillis() / 1000
                response.close()
                return chain.proceed(sign(original, token, secret))
            }
        }
        return response
    }

    private fun sign(request: Request, token: String, secret: String): Request {
        val timestamp = (System.currentTimeMillis() / 1000 + store.clockOffsetSeconds).toString()
        val nonce = UUID.randomUUID().toString().replace("-", "")
        val body = request.body?.let { b -> Buffer().also { b.writeTo(it) }.readByteArray() } ?: ByteArray(0)

        val url = request.url
        val query = (0 until url.querySize).map { url.queryParameterName(it) to (url.queryParameterValue(it) ?: "") }

        val toSign = RequestSigner.stringToSign(request.method, url.encodedPath, query,
                                                timestamp, nonce, store.deviceId, body)

        return request.newBuilder()
            .header("X-Device-Id", store.deviceId)
            .header("X-Device-Token", token)
            .header("X-Timestamp", timestamp)
            .header("X-Nonce", nonce)
            .header("X-Signature", RequestSigner.hmacHex(secret.toByteArray(Charsets.UTF_8), toSign))
            .build()
    }
}
```

Test unitaire :

```kotlin
@Test fun referenceVector() {
    val s = RequestSigner.stringToSign(
        "POST", "/v1/auth/login", listOf("b" to "2", "a" to "hello world", "a" to "1"),
        "1700000000", "abcdef0123456789", "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
        """{"email":"a@b.fr","password":"secret123"}""".toByteArray())
    assertEquals("92f9f6b9bfa2c96ac205992da06013833125905d831c28ad58add58200e14c04",
                 RequestSigner.hmacHex("test-secret".toByteArray(), s))
}
```

Notes Android :

- Construire les URL avec `HttpUrl.Builder().addQueryParameter(...)` : OkHttp encode alors `+` en `%2B`, comme attendu.
- Placer `SignatureInterceptor` **en dernier** (`addInterceptor` après les autres) pour que le corps signé soit exactement celui envoyé ; éviter un intercepteur de compression du corps après lui.
- Stocker les identifiants avec `EncryptedSharedPreferences` (clé maître dans Android Keystore). Exclure ce fichier des sauvegardes (`android:fullBackupContent` / `dataExtractionRules`).
- La clé application ne doit pas apparaître en clair dans le code : la reconstituer à l'exécution (NDK ou obfuscation) et activer R8.

## Gestion des jetons utilisateur

- Stocker `access_token` et `refresh_token` au même endroit sécurisé que les identifiants appareil.
- Avant chaque requête 👤 : si `access_token` expire dans moins de 30 s, rafraîchir d'abord.
- Le rafraîchissement doit être **exclusif** : un seul appel `/auth/refresh` à la fois, les autres requêtes attendent son résultat (acteur Swift / `Mutex` Kotlin). Un refresh token réutilisé révoque la session.
- Après `/auth/refresh`, remplacer **les deux** jetons.

## Envoi de fichiers (CV, lettre de motivation)

Les PDF sont envoyés en **corps brut** (`Content-Type: application/pdf`), pas en multipart : on signe exactement comme une requête JSON, en hachant les octets du fichier.

```swift
var request = URLRequest(url: RequestSigner.makeURL(base: api, path: "/v1/documents",
    query: [.init(name: "kind", value: "cv"), .init(name: "filename", value: pdfURL.lastPathComponent)]))
request.httpMethod = "POST"
request.setValue("application/pdf", forHTTPHeaderField: "Content-Type")
request.httpBody = try Data(contentsOf: pdfURL)      // 5 Mo max
RequestSigner.sign(&request, deviceId: deviceId, credential: .device(token: token, secret: secret), clockOffset: offset)
```

```kotlin
val body = pdfBytes.toRequestBody("application/pdf".toMediaType())
val url = baseUrl.newBuilder().addPathSegments("v1/documents")
    .addQueryParameter("kind", "cv").addQueryParameter("filename", fileName).build()
client.newCall(Request.Builder().url(url).post(body).build())  // SignatureInterceptor signe les octets
```

La réponse contient l'`id` du document, à passer ensuite dans `POST /v1/jobs/{id}/apply`.

## Messagerie : bonnes pratiques côté app

- Générer un `client_id` (UUID) **par message** et l'envoyer avec : en cas de nouvel essai après une coupure réseau, le serveur ne crée pas de doublon. Afficher le message immédiatement (« en cours d'envoi »), puis le remplacer par la réponse du serveur.
- Conversation ouverte : `GET …/messages?after=<newest_id>` toutes les 5 s (`poll_interval`), suspendu quand l'app passe en arrière-plan ; puis `POST …/read`.
- À l'arrivée d'une notification `{type: "message", conversation_id}` : rafraîchir le badge (`GET /conversations/unread-count`) et, au tap, ouvrir la conversation.
- Enregistrer le jeton push après autorisation : `PATCH /v1/devices/me { "push_token": "…" }` (et à chaque changement de jeton).

## Carte : chargement le plus rapide

```swift
// 1. Afficher tout de suite les repères mémorisés (fichier local), puis revalider
var request = URLRequest(url: RequestSigner.makeURL(base: api, path: "/v1/places/pins",
    query: [.init(name: "near", value: "\(lat),\(lng)")]))
if let etag = cache.etag { request.setValue(etag, forHTTPHeaderField: "If-None-Match") }
RequestSigner.sign(&request, deviceId: deviceId, credential: .device(token: token, secret: secret), clockOffset: offset)
let (data, response) = try await URLSession.shared.data(for: request)
if (response as? HTTPURLResponse)?.statusCode == 304 { return }      // rien n'a changé
cache.save(data, etag: (response as? HTTPURLResponse)?.value(forHTTPHeaderField: "ETag"))
```

- Désactiver le cache HTTP propre à `URLSession` pour cette requête (`cachePolicy = .reloadIgnoringLocalCacheData`) : c'est l'app qui gère l'ETag.
- Le regroupement des repères se fait localement (`MKMarkerAnnotationView.clusteringIdentifier`) : aucun appel réseau en zoomant.
- Flux progressif (premier lancement) : `for try await line in URLSession.shared.bytes(for: request).0.lines` ; une ligne `event:` puis une ligne `data:` par événement ; ajouter les repères de chaque événement `pins` dès sa réception.
