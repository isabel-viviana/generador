@extends('layouts.app')

@section('title', 'Editar {{SINGULAR_NAME}} #' . ($item['id'] ?? ''))

@section('content')
<div class="crud-card" style="max-width: 850px; margin: 0 auto;">
    <div class="card-header">
        <h1 class="card-title">Editar {{SINGULAR_NAME}} #{{ $item['id'] ?? '' }}</h1>
        <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">← Volver</a>
    </div>

    <form action="{{ route('{{RECURSO}}.update', $item['id']) }}" method="POST"{{FORM_ENCTYPE}}>
        @csrf
        @method('PUT')
        <div class="card-body">
            <div class="form-grid">
{{FORM_FIELDS_EDIT}}
            </div>
        </div>

        <div class="card-footer" style="justify-content: flex-end;">
            <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
    </form>
</div>
@endsection
