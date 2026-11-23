@extends('layouts.app')

@section('title', 'Detalle de {{SINGULAR_NAME}} #' . ($item['id'] ?? ''))

@section('content')
<div class="crud-card" style="max-width: 900px; margin: 0 auto;">
    <div class="card-header">
        <h1 class="card-title">Detalle de {{SINGULAR_NAME}} #{{ $item['id'] ?? '' }}</h1>
        <div class="btn-group">
            <a href="{{ route('{{RECURSO}}.edit', $item['id']) }}" class="btn btn-primary">Editar</a>
            <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">Volver al listado</a>
        </div>
    </div>

    <div class="card-body">
        <div class="details-list">
            <div class="detail-item">
                <span class="detail-label">ID</span>
                <span class="detail-value">#{{ $item['id'] ?? '-' }}</span>
            </div>
{{SHOW_FIELDS}}
        </div>
    </div>

    <div class="card-footer" style="justify-content: space-between;">
        <form action="{{ route('{{RECURSO}}.destroy', $item['id']) }}" method="POST" onsubmit="return confirm('¿Está seguro de eliminar este registro permanentemente?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Eliminar Registro</button>
        </form>

        <a href="{{ route('{{RECURSO}}.index') }}" class="btn btn-secondary">Regresar</a>
    </div>
</div>
@endsection
