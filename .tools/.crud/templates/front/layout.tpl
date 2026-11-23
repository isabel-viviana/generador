<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title', '{{APP_NAME}}') - Panel CRUD</title>
    <link rel="stylesheet" href="{{ asset('css/crud.css') }}">
</head>
<body>
    <div class="crud-wrapper">
        <!-- Barra de Navegación Superior -->
        <header class="crud-navbar">
            <div class="navbar-brand">
                <span class="brand-logo">⚡</span>
                <span class="brand-title">{{APP_NAME}}</span>
            </div>
            <div class="navbar-actions">
                <span class="badge badge-info">Frontend Web</span>
            </div>
        </header>

        <div class="crud-body">
            <!-- Menú Lateral -->
            <aside class="crud-sidebar">
                <div class="sidebar-heading">MÓDULOS CRUD</div>
                <nav class="sidebar-nav">
{{NAV_LINKS}}
                </nav>
            </aside>

            <!-- Contenido Principal -->
            <main class="crud-content">
                @if(session('success'))
                    <div class="alert alert-success">
                        <strong>¡Éxito!</strong> {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger">
                        <strong>Error:</strong> {{ session('error') }}
                    </div>
                @endif

                @if(isset($apiError))
                    <div class="alert alert-warning">
                        <strong>Aviso de API:</strong> {{ $apiError }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger">
                        <strong>Por favor corrija los siguientes errores:</strong>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
