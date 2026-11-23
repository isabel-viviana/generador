<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Cloudinary
{
    /**
     * Sube un archivo de imagen a Cloudinary mediante llamada HTTP autenticada con firma sha1.
     *
     * @param UploadedFile $file
     * @param string|null $folder
     * @return string URL segura de la imagen subida.
     * @throws RuntimeException
     */
    public static function upload(UploadedFile $file, ?string $folder = null): string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $defaultFolder = config('services.cloudinary.folder', 'crud_uploads');

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            throw new RuntimeException('Cloudinary no está configurado. Verifique las credenciales en el archivo .env del Backend.');
        }

        $folderToUse = $folder ?: $defaultFolder;
        $timestamp = time();

        // Parámetros a firmar ordenados alfabéticamente
        // folder=X&timestamp=T
        $paramsToSign = [
            'folder' => $folderToUse,
            'timestamp' => $timestamp,
        ];
        ksort($paramsToSign);

        $toSignString = '';
        foreach ($paramsToSign as $key => $val) {
            $toSignString .= "$key=$val&";
        }
        $toSignString = rtrim($toSignString, '&') . $apiSecret;
        $signature = sha1($toSignString);

        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

        $response = Http::timeout(25)
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($endpoint, [
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folderToUse,
                'signature' => $signature,
            ]);

        if (!$response->successful()) {
            $errorMsg = $response->json('error.message') ?? 'Error en la comunicación con Cloudinary.';
            throw new RuntimeException("Error al subir imagen a Cloudinary: {$errorMsg}");
        }

        $secureUrl = $response->json('secure_url');
        if (empty($secureUrl)) {
            throw new RuntimeException('Cloudinary no devolvió una URL válida.');
        }

        return $secureUrl;
    }
}
