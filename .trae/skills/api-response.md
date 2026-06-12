# Skill: ApiResponse

## Quando Usar
Em todo controller da aplicação. Nunca retornar
`response()->json()` diretamente.

## Instalação
Criar em `app/Http/Responses/ApiResponse.php`:

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(
        mixed  $data    = [],
        string $message = '',
        int    $status  = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(
        string $message = 'Erro interno',
        mixed  $errors  = [],
        int    $status  = 400
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        string               $message = ''
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }
}
```

## Uso nos Controllers

```php
use App\Http\Responses\ApiResponse;

// Sucesso simples
return ApiResponse::success($campaign, 'Campanha criada.');

// Sucesso com status 201
return ApiResponse::success($model, 'Recurso criado.', 201);

// Sucesso sem dados
return ApiResponse::success([], 'Operação realizada.');

// Erro de validação
return ApiResponse::error('Dados inválidos.', $validator->errors(), 422);

// Erro de autorização
return ApiResponse::error('Acesso negado.', [], 403);

// Erro not found
return ApiResponse::error('Recurso não encontrado.', [], 404);

// Erro interno
return ApiResponse::error('Erro ao processar.', [], 500);

// Lista paginada
$campaigns = Campaign::paginate(20);
return ApiResponse::paginated($campaigns, 'Campanhas carregadas.');
```

## Formato de Resposta

### Sucesso
```json
{
  "success": true,
  "message": "Campanha criada.",
  "data": { "id": 1, "name": "..." }
}
```

### Erro
```json
{
  "success": false,
  "message": "Dados inválidos.",
  "errors": { "name": ["O nome é obrigatório."] }
}
```

### Paginado
```json
{
  "success": true,
  "message": "",
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 98,
    "from": 1,
    "to": 20
  }
}
```

## Nunca Fazer
```php
// ❌ Nunca retornar json direto
return response()->json(['data' => $campaign]);

// ❌ Nunca retornar array
return $campaign->toArray();

// ❌ Nunca misturar formatos
return response()->json(['success' => true, 'campaign' => $campaign]);
```
