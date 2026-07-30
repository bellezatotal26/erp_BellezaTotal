<?php

namespace SoftNova\Services\Integrations;

/**
 * Contrato para importar catálogo de productos externos al inventario.
 */
interface CatalogProviderInterface
{
    public function code(): string;
    public function label(): string;
    public function status(): array;
    public function testConnection(): array;

    /**
     * @return array<int, array{external_id:string,code?:string,name:string,price:float,cost?:float,stock?:int,description?:string}>
     */
    public function fetchProducts(int $limit = 100): array;
}
