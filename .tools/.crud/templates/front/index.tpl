@extends('layouts.app')

@section('title', 'Listado de {{PLURAL_NAME}}')

@section('content')
<div class="crud-card">
    <div class="card-header">
        <h1 class="card-title">{{PLURAL_NAME}}</h1>
        <a href="{{ route('{{RECURSO}}.create') }}" class="btn btn-primary">
            + Nuevo {{SINGULAR_NAME}}
        </a>
    </div>

    <div class="card-body">
        @if(empty($items) || count($items) === 0)
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <h3>No hay {{PLURAL_LOWER}} registrados</h3>
                <p>Comienza creando el primer registro en el sistema.</p>
                <div style="margin-top: 1rem;">
                    <a href="{{ route('{{RECURSO}}.create') }}" class="btn btn-primary">+ Crear {{SINGULAR_NAME}}</a>
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="crud-table">
                    <thead>
                        <tr>
                            <th># ID</th>
{{TABLE_HEADERS}}
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td><strong>#{{ $item['id'] }}</strong></td>
{{TABLE_CELLS}}
                                <td style="text-align: right;">
                                    <div class="btn-group" style="justify-content: flex-end;">
                                        <a href="{{ route('{{RECURSO}}.show', $item['id']) }}" class="btn btn-sm btn-secondary">Ver</a>
                                        <a href="{{ route('{{RECURSO}}.edit', $item['id']) }}" class="btn btn-sm btn-primary">Editar</a>
                                        <form action="{{ route('{{RECURSO}}.destroy', $item['id']) }}" method="POST" onsubmit="return confirm('¿Está seguro de eliminar este registro?');" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
