<?php

declare(strict_types=1);

/** Contrato para clientes que admiten un numero acotado de solicitudes concurrentes. */
interface BrightspaceBatchClient
{
    /**
     * @param array<int, array{method: string, product: string, path: string, query?: array, body?: array}> $requests
     * @return array<int, array{status: int, data: array<string|int, mixed>|null, error: string|null}>
     */
    public function requestBatchJson(array $requests): array;
}
