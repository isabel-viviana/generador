<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class {{CLASS_NAME}}Controller extends Controller
{
    /**
     * URL base de la API del Backend.
     */
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.backend.api_url', env('BACKEND_API_URL', 'http://127.0.0.1:8001/api')), '/');
    }

    /**
     * Listar todos los registros consultando la API Backend.
     */
    public function index()
    {
        try {
            $response = Http::timeout(12)->get("{$this->apiUrl}/{{RECURSO}}");

            if ($response->successful()) {
                $items = $response->json('data') ?? [];
                return view('crud.{{RECURSO}}.index', compact('items'));
            }

            return view('crud.{{RECURSO}}.index', [
                'items' => [],
                'apiError' => 'Error al consultar la API Backend: ' . ($response->json('message') ?? ('Código HTTP ' . $response->status()))
            ]);
        } catch (Throwable $e) {
            return view('crud.{{RECURSO}}.index', [
                'items' => [],
                'apiError' => 'No se pudo conectar con la API Backend (' . $e->getMessage() . '). Verifique que el servicio Backend esté en ejecución en el puerto 8001.'
            ]);
        }
    }

    /**
     * Mostrar formulario para crear un nuevo registro.
     */
    public function create()
    {
{{LOAD_RELATIONS_CREATE}}
        return view('crud.{{RECURSO}}.create', {{COMPACT_RELATIONS_CREATE}});
    }

    /**
     * Enviar los datos del nuevo registro a la API Backend.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->except([{{EXCEPT_IMAGE_FIELDS}}]);
            $http = Http::timeout(25);
{{ATTACH_FILES_STORE}}
            $response = $http->post("{$this->apiUrl}/{{RECURSO}}", $data);

            if ($response->successful()) {
                return redirect()->route('{{RECURSO}}.index')
                    ->with('success', '{{SINGULAR_NAME}} creado exitosamente.');
            }

            if ($response->status() === 422) {
                $errors = $response->json('errors') ?? [];
                return back()->withErrors($errors)->withInput();
            }

            $msg = $response->json('message') ?? 'Error al procesar la solicitud en el Backend.';
            return back()->with('error', $msg)->withInput();
        } catch (Throwable $e) {
            return back()->with('error', 'Error al comunicarse con la API: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Mostrar los detalles de un registro específico.
     */
    public function show($id)
    {
        try {
            $response = Http::timeout(12)->get("{$this->apiUrl}/{{RECURSO}}/{$id}");

            if ($response->successful()) {
                $item = $response->json('data');
                return view('crud.{{RECURSO}}.show', compact('item'));
            }

            return redirect()->route('{{RECURSO}}.index')
                ->with('error', 'El registro solicitado no fue encontrado en el Backend.');
        } catch (Throwable $e) {
            return redirect()->route('{{RECURSO}}.index')
                ->with('error', 'Error de conexión con la API: ' . $e->getMessage());
        }
    }

    /**
     * Mostrar formulario para editar un registro existente.
     */
    public function edit($id)
    {
        try {
            $response = Http::timeout(12)->get("{$this->apiUrl}/{{RECURSO}}/{$id}");

            if (!$response->successful()) {
                return redirect()->route('{{RECURSO}}.index')
                    ->with('error', 'El registro solicitado no fue encontrado en el Backend.');
            }

            $item = $response->json('data');
{{LOAD_RELATIONS_EDIT}}
            return view('crud.{{RECURSO}}.edit', {{COMPACT_RELATIONS_EDIT}});
        } catch (Throwable $e) {
            return redirect()->route('{{RECURSO}}.index')
                ->with('error', 'Error de conexión con la API: ' . $e->getMessage());
        }
    }

    /**
     * Actualizar los datos de un registro en la API Backend.
     */
    public function update(Request $request, $id)
    {
        try {
            $data = $request->except([{{EXCEPT_IMAGE_FIELDS}}]);
            $http = Http::timeout(25);
            $tieneArchivo = false;
{{ATTACH_FILES_UPDATE}}
            if ($tieneArchivo) {
                // Laravel interpreta archivos multipart en update usando POST con _method=PUT
                $data['_method'] = 'PUT';
                $response = $http->post("{$this->apiUrl}/{{RECURSO}}/{$id}", $data);
            } else {
                $response = $http->put("{$this->apiUrl}/{{RECURSO}}/{$id}", $data);
            }

            if ($response->successful()) {
                return redirect()->route('{{RECURSO}}.index')
                    ->with('success', '{{SINGULAR_NAME}} actualizado exitosamente.');
            }

            if ($response->status() === 422) {
                $errors = $response->json('errors') ?? [];
                return back()->withErrors($errors)->withInput();
            }

            $msg = $response->json('message') ?? 'Error al actualizar el registro en el Backend.';
            return back()->with('error', $msg)->withInput();
        } catch (Throwable $e) {
            return back()->with('error', 'Error al comunicarse con la API: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Eliminar un registro en la API Backend.
     */
    public function destroy($id)
    {
        try {
            $response = Http::timeout(12)->delete("{$this->apiUrl}/{{RECURSO}}/{$id}");

            if ($response->successful()) {
                return redirect()->route('{{RECURSO}}.index')
                    ->with('success', '{{SINGULAR_NAME}} eliminado exitosamente.');
            }

            $msg = $response->json('message') ?? 'No se pudo eliminar el registro en el Backend.';
            return redirect()->route('{{RECURSO}}.index')->with('error', $msg);
        } catch (Throwable $e) {
            return redirect()->route('{{RECURSO}}.index')
                ->with('error', 'Error de conexión con la API: ' . $e->getMessage());
        }
    }
}
