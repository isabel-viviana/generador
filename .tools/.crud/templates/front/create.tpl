@extends('layouts.app')

@section('title', 'Crear {{SINGULAR_NAME}}')

@section('content')
<div class="crud-card" style="max-width: 850px; margin: 0 auto;">
    <div class="card-header">
        <h1 class="card-title">Crear Nuevo {{SINGULAR_NAME}}</h1>
        <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">← Volver</a>
    </div>

    <form action="{{ route('{{RECURSO}}.store') }}" method="POST"{{FORM_ENCTYPE}}>
        @csrf
        <div class="card-body">
            <div class="form-grid">
{{FORM_FIELDS_CREATE}}
            </div>
        </div>

        <div class="card-footer" style="justify-content: flex-end;">
            <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success">Guardar {{SINGULAR_NAME}}</button>
        </div>
    </form>
</div>
@endsection
