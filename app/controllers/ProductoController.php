<?php

require_once __DIR__ . '/../models/Producto.php';

class ProductoController
{
    private Producto $productoModel;

    public function __construct()
    {
        $this->productoModel = new Producto();
    }

    public function index(string $search = ''): array
    {
        return $this->productoModel->getAll(trim($search));
    }

    public function categorias(): array
    {
        return $this->productoModel->getCategorias();
    }

    public function proveedores(): array
    {
        return $this->productoModel->getProveedores();
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
            'proveedor_id' => trim($data['proveedor_id'] ?? ''),
            'laboratorio' => trim($data['laboratorio'] ?? ''),
            'unidad_medida' => trim($data['unidad_medida'] ?? ''),
            'precio_compra' => trim($data['precio_compra'] ?? '0'),
            'precio_venta' => trim($data['precio_venta'] ?? '0'),
            'stock_minimo' => trim($data['stock_minimo'] ?? '0'),
            'stock_maximo' => trim($data['stock_maximo'] ?? '0'),
            'ubicacion' => trim($data['ubicacion'] ?? ''),
            'existencia_actual' => trim($data['existencia_actual'] ?? '0'),
        ];
    }

    private function validate(array $data, ?int $id = null): array
    {
        if ($data['codigo'] === '') {
            return ['success' => false, 'message' => 'El código es obligatorio.'];
        }

        if ($data['descripcion'] === '') {
            return ['success' => false, 'message' => 'La descripción es obligatoria.'];
        }

        if (!is_numeric($data['precio_compra']) || $data['precio_compra'] < 0) {
            return ['success' => false, 'message' => 'El precio de compra no es válido.'];
        }

        if (!is_numeric($data['precio_venta']) || $data['precio_venta'] < 0) {
            return ['success' => false, 'message' => 'El precio de venta no es válido.'];
        }

        if (!is_numeric($data['stock_minimo']) || (int)$data['stock_minimo'] < 0) {
            return ['success' => false, 'message' => 'El stock mínimo no es válido.'];
        }

        if (!is_numeric($data['stock_maximo']) || (int)$data['stock_maximo'] < 0) {
            return ['success' => false, 'message' => 'El stock máximo no es válido.'];
        }

        if (!is_numeric($data['existencia_actual']) || (int)$data['existencia_actual'] < 0) {
            return ['success' => false, 'message' => 'La existencia actual no es válida.'];
        }

        if ($this->productoModel->existsByCodigo($data['codigo'], $id)) {
            return ['success' => false, 'message' => 'Ya existe un producto con ese código.'];
        }

        return ['success' => true];
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
            return ['success' => false, 'message' => 'No se pudo registrar el producto.'];
        }

        return ['success' => true, 'message' => 'Producto registrado correctamente.'];
    }

    public function update(int $id, array $postData): array
    {
        $data = $this->sanitizeData($postData);
        $validation = $this->validate($data, $id);

        if (!$validation['success']) {
            return $validation;
        }

        $ok = $this->productoModel->update($id, $data);

        if (!$ok) {
            return ['success' => false, 'message' => 'No se pudo actualizar el producto.'];
        }

        return ['success' => true, 'message' => 'Producto actualizado correctamente.'];
    }

    public function destroy(int $id): array
    {
        $producto = $this->productoModel->findById($id);

        if (!$producto) {
            return ['success' => false, 'message' => 'El producto no existe.'];
        }

        $ok = $this->productoModel->deleteLogical($id);

        if (!$ok) {
            return ['success' => false, 'message' => 'No se pudo eliminar el producto.'];
        }

        return ['success' => true, 'message' => 'Producto eliminado correctamente.'];
    }
}