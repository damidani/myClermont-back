<?php

declare(strict_types=1);

namespace App\Content\Documents;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Envoi de documents en corps brut (pas de multipart) : la signature HMAC couvre ainsi
 * exactement les octets du fichier, comme pour toute autre requête.
 *
 *   POST /v1/documents?kind=cv&filename=Mon%20CV.pdf
 *   Content-Type: application/pdf
 *   <octets du PDF>
 */
final class DocumentController
{
    public function upload(Request $request): Response
    {
        $type = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));
        if ($type !== 'application/pdf') {
            throw new HttpException(415, 'unsupported_document', 'Envoyer le PDF avec Content-Type: application/pdf.');
        }
        $q = $request->query();

        $doc = (new DocumentStore())->store(
            $request->body,
            (string) ($q['kind'] ?? ''),
            $request->attributes['device'],
            $request->attributes['user_id'] ?? null,
            isset($q['filename']) ? (string) $q['filename'] : null,
        );

        return Response::success($doc, 201);
    }

    public function delete(Request $request): Response
    {
        (new DocumentStore())->delete((string) $request->param('id'), $request->attributes['device']);

        return Response::noContent();
    }
}
