<?php

require_once __DIR__ . '/../models/Producto.php';

class ProductoController
{
    private Producto $productoModel;

    public function __construct()
    {
        $this->productoModel = new Producto();
    }

    private function esAdministrador(): bool
    {
        $user = $_SESSION['user'] ?? null;

        return isset($user['rol'])
            && in_array(strtoupper(trim($user['rol'])), ['ADMINISTRADOR', 'ADMIN'], true);
    }

    private function sucursalUsuario(): string
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user) {
            return '';
        }

        $almacenNombre = strtoupper(trim($user['almacen_nombre'] ?? ''));

        if (str_contains($almacenNombre, 'HIDALGO')) {
            return 'CIUDAD HIDALGO';
        }

        if (str_contains($almacenNombre, 'TUXTLA')) {
            return 'TUXTLA';
        }

        $almacenId = (int)($user['almacen_id'] ?? 0);

        if ($almacenId === 1) {
            return 'CIUDAD HIDALGO';
        }

        if ($almacenId === 2 || $almacenId === 3) {
            return 'TUXTLA';
        }

        return '';
    }

    public function index(
    string $search = '',
    string $sucursal = '',
    string $categoriaId = '',
    string $proveedor = '',
    string $ubicacion = '',
    string $estadoStock = ''
): array {
    $isAdmin = $this->esAdministrador();

    if (!$isAdmin) {
        $sucursal = $this->sucursalUsuario();
    }

    return $this->productoModel->getAll(
        trim($search),
        trim($sucursal),
        $isAdmin,
        trim($categoriaId),
        '',
        strtoupper(trim($ubicacion)),
        trim($estadoStock)
    );
}

    public function count(string $search = '', string $sucursal = ''): int
    {
        return $this->productoModel->countAll(trim($search));
    }

    public function categorias(): array
    {
        return $this->productoModel->getCategorias();
    }

    public function proveedores(): array
    {
        return $this->productoModel->getProveedores();
    }

    public function almacenes(): array
    {
        return $this->productoModel->getAlmacenes();
    }

    public function find(int $id): ?array
    {
        return $this->productoModel->findById($id);
    }

    private function sanitizeData(array $data): array
    {
        return [
            'codigo' => trim($data['codigo'] ?? ''),
            'codigo_barras' => trim($data['codigo_barras'] ?? ''),
            'descripcion' => trim($data['descripcion'] ?? ''),
            'categoria_id' => trim($data['categoria_id'] ?? ''),
            'proveedor_nombre' => trim($data['proveedor_nombre'] ?? ''),
            'laboratorio' => trim($data['laboratorio'] ?? ''),
            'unidad_medida' => trim($data['unidad_medida'] ?? ''),
            'precio_compra' => trim($data['precio_compra'] ?? '0'),
            'precio_venta' => trim($data['precio_venta'] ?? '0'),
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => strtoupper(trim($data['ubicacion'] ?? '')),
        ];
    }

    private function validate(array $data, ?int $id = null): array
    {
        if ($data['codigo'] === '') {
            return [
                'success' => false,
                'message' => 'El código es obligatorio.'
            ];
        }

        if ($data['descripcion'] === '') {
            return [
                'success' => false,
                'message' => 'La descripción es obligatoria.'
            ];
        }

        if (!is_numeric($data['precio_compra']) || (float)$data['precio_compra'] < 0) {
            return [
                'success' => false,
                'message' => 'El precio unitario no es válido.'
            ];
        }

        if (!is_numeric($data['precio_venta']) || (float)$data['precio_venta'] < 0) {
            return [
                'success' => false,
                'message' => 'El precio de venta no es válido.'
            ];
        }

        if ($this->productoModel->existsByCodigo($data['codigo'], $id)) {
            return [
                'success' => false,
                'message' => 'Ya existe un producto con ese código en el catálogo.'
            ];
        }

        return [
            'success' => true
        ];
    }

    public function store(array $postData): array
    {
        $data = $this->sanitizeData($postData);

        $validation = $this->validate($data);

        if (!$validation['success']) {
            return $validation;
        }

        $ok = $this->productoModel->create($data);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo registrar el producto.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Producto registrado correctamente.'
        ];
    }

    public function update(int $id, array $postData): array
    {
        $productoActual = $this->productoModel->findById($id);

        if (!$productoActual) {
            return [
                'success' => false,
                'message' => 'El producto no existe.'
            ];
        }

        $data = $this->sanitizeData($postData);

        $validation = $this->validate($data, $id);

        if (!$validation['success']) {
            return $validation;
        }

        $ok = $this->productoModel->update($id, $data);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo actualizar el producto.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Producto actualizado correctamente.'
        ];
    }

    public function importarInformacion(array $data): array
    {
        $codigo = trim($data['codigo'] ?? '');

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'El código de barras es obligatorio.'
            ];
        }

        $ok = $this->productoModel->crearOActualizarCatalogo([
            'codigo' => $codigo,
            'codigo_barras' => $data['codigo_barras'] ?? $codigo,
            'descripcion' => $data['descripcion'] ?? '',
            'laboratorio' => $data['marca'] ?? '',
            'unidad_medida' => '',
            'categoria_id' => null,
            'proveedor_nombre' => '',
            'precio_compra' => 0,
            'precio_venta' => 0,
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'ubicacion' => '',
        ]);

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Producto importado correctamente.'
                : 'No se pudo importar el producto.'
        ];
    }

    public function importarExistencia(
        string $codigo,
        string $sucursal,
        int $existencia
    ): array {
        $codigo = trim($codigo);
        $sucursal = strtoupper(trim($sucursal));

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'El código de barras es obligatorio.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'La sucursal es obligatoria.'
            ];
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        $ok = $this->productoModel->actualizarExistenciaPorCodigo(
            $codigo,
            $sucursal,
            $existencia
        );

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Existencia actualizada correctamente.'
                : 'No se encontró el producto en el catálogo.'
        ];
    }

    public function guardarUbicacionExistencia(array $postData): array
    {
        $codigo = trim($postData['codigo'] ?? '');
        $sucursal = strtoupper(trim($postData['sucursal'] ?? ''));
        $ubicacion = strtoupper(trim($postData['ubicacion'] ?? ''));
        $existencia = (int)($postData['existencia'] ?? 0);

        if ($codigo === '') {
            return [
                'success' => false,
                'message' => 'Código vacío.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal vacía.'
            ];
        }

        if ($ubicacion === '') {
            $ubicacion = 'SIN UBICACION';
        }

        if ($existencia < 0) {
            $existencia = 0;
        }

        $ok = $this->productoModel->actualizarExistenciaPorCodigo(
            $codigo,
            $sucursal,
            $existencia,
            $ubicacion
        );

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Ubicación guardada correctamente.'
                : 'No se pudo guardar.'
        ];
    }

    public function editarUbicacionExistencia(array $postData): array
    {
        $productoId = (int)($postData['producto_id'] ?? 0);
        $sucursal = strtoupper(trim($postData['sucursal'] ?? ''));
        $ubicacionAnterior = strtoupper(trim($postData['ubicacion_anterior'] ?? ''));
        $ubicacionNueva = strtoupper(trim($postData['ubicacion_nueva'] ?? ''));
        $existencia = (int)($postData['existencia'] ?? 0);

        if ($productoId <= 0) {
            return [
                'success' => false,
                'message' => 'Producto inválido.'
            ];
        }

        if ($sucursal === '') {
            return [
                'success' => false,
                'message' => 'Sucursal inválida.'
            ];
        }

        if ($ubicacionAnterior === '') {
            $ubicacionAnterior = 'SIN UBICACION';
        }

        if ($ubicacionNueva === '') {
            $ubicacionNueva = 'SIN UBICACION';
        }

        if ($existencia <= 0) {
            $ok = $this->productoModel->eliminarUbicacionExistencia(
                $productoId,
                $sucursal,
                $ubicacionAnterior
            );

            return [
                'success' => $ok,
                'message' => $ok
                    ? 'Ubicación eliminada correctamente.'
                    : 'No se pudo eliminar la ubicación.'
            ];
        }

        $ok = $this->productoModel->actualizarUbicacionExistencia(
            $productoId,
            $sucursal,
            $ubicacionAnterior,
            $ubicacionNueva,
            $existencia
        );

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Ubicación actualizada correctamente.'
                : 'No se pudo actualizar la ubicación.'
        ];
    }

    public function destroy(int $id): array
    {
        $producto = $this->productoModel->findById($id);

        if (!$producto) {
            return [
                'success' => false,
                'message' => 'El producto no existe.'
            ];
        }

        $ok = $this->productoModel->deleteLogical($id);

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'No se pudo eliminar el producto.'
            ];
        }

        return [
            'success' => true,
            'message' => 'Producto eliminado correctamente.'
        ];
    }
}