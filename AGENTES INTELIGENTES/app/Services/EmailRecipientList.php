<?php

declare(strict_types=1);

/** Normaliza y valida listas de destinatarios compatibles con Brightspace. */
final class EmailRecipientList
{
    public const MAX_LENGTH = 2048;
    public const MAX_ADDRESS_LENGTH = 254;
    public const DYNAMIC_RECIPIENT_TOKEN = '{InitiatingUser}';

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Ingresa uno o más correos válidos, separados por coma o punto y coma (máximo 2.048 caracteres).'
            );
        }

        if ($value === self::DYNAMIC_RECIPIENT_TOKEN) {
            return $value;
        }

        $addresses = preg_split('/[;,]/', $value);
        if (!is_array($addresses) || $addresses === []) {
            throw new InvalidArgumentException('No se pudo interpretar la lista de destinatarios.');
        }

        $normalizedAddresses = [];
        $seen = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            if (
                $address === ''
                || $address === self::DYNAMIC_RECIPIENT_TOKEN
                || mb_strlen($address, 'UTF-8') > self::MAX_ADDRESS_LENGTH
                || filter_var($address, FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new InvalidArgumentException(
                    'Revisa la lista: cada destinatario debe ser un correo válido y no puede haber separadores vacíos.'
                );
            }

            $key = strtolower($address);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('La lista no puede contener correos duplicados.');
            }

            $seen[$key] = true;
            $normalizedAddresses[] = $address;
        }

        $normalized = implode(', ', $normalizedAddresses);
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidArgumentException('La lista de destinatarios no puede superar 2.048 caracteres.');
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $agents */
    public static function fromEmailActionCc(array $agents): string
    {
        $addresses = [];
        $seen = [];
        $hasDynamicRecipient = false;

        foreach ($agents as $agent) {
            $emailAction = $agent['Action']['EmailAction'] ?? null;
            if (!is_array($emailAction)) {
                continue;
            }

            $value = $emailAction['Cc'] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $normalized = self::normalize($value);
            if ($normalized === self::DYNAMIC_RECIPIENT_TOKEN) {
                $hasDynamicRecipient = true;
                continue;
            }

            foreach (explode(', ', $normalized) as $address) {
                $key = strtolower($address);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $addresses[] = $address;
                }
            }
        }

        if ($hasDynamicRecipient && $addresses !== []) {
            throw new InvalidArgumentException(
                'Brightspace tiene valores CC distintos entre {InitiatingUser} y correos. Revisa las acciones de los agentes antes de unificarlos.'
            );
        }

        if ($hasDynamicRecipient) {
            return self::DYNAMIC_RECIPIENT_TOKEN;
        }

        return $addresses === [] ? '' : self::normalize(implode(', ', $addresses));
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array{value: string, agents: array<int, string>}>
     */
    public static function groupEmailActionCc(array $agents): array
    {
        $groups = [];

        foreach ($agents as $agent) {
            $emailAction = $agent['Action']['EmailAction'] ?? null;
            if (!is_array($emailAction)) {
                continue;
            }

            $value = $emailAction['Cc'] ?? null;
            $normalized = is_string($value) && trim($value) !== ''
                ? self::normalizeForComparison($value)
                : '';
            $normalized = is_string($normalized) ? $normalized : '';

            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'value' => $normalized,
                    'agents' => [],
                ];
            }

            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $groups[$normalized]['agents'][] = (string) (
                $agent['Name'] ?? ($agentId > 0 ? 'Agente ' . $agentId : 'Agente sin nombre')
            );
        }

        return array_values($groups);
    }

    public static function normalizeForComparison(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return self::normalize($value);
        } catch (InvalidArgumentException) {
            return $value;
        }
    }
}
