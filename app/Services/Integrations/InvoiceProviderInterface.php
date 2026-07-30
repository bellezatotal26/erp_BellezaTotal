<?php

namespace SoftNova\Services\Integrations;

interface InvoiceProviderInterface
{
    public function code(): string;

    public function label(): string;

    /** @return array{enabled:bool,configured:bool,active:bool,sync?:array,meta?:array} */
    public function status(): array;

    /** @return array{success:bool,message:string,company?:mixed} */
    public function testConnection(): array;

    /**
     * Stub / implementación futura de emisión de factura electrónica.
     *
     * @return array{success:bool,queued?:bool,message:string,payload_preview?:array}
     */
    public function pushSale(array $sale, array $items = [], array $payments = []): array;
}
