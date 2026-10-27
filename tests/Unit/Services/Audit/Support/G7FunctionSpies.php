<?php

declare(strict_types=1);

/*
 * Recording pass-through spies for global functions called unqualified from the
 * App\Services\Audit namespace. PHP resolves such calls to a namespaced function
 * first, so these wrappers see the exact arguments (timeouts, ports, cURL options)
 * and then delegate to the real implementation unchanged.
 *
 * Calls are recorded in the autoloaded G7FunctionSpy class. This file holds no
 * class of its own, so it is loaded with require_once from the adapter tests
 * before any test runs.
 */

namespace App\Services\Audit {
    use CurlHandle;
    use Tests\Unit\Services\Audit\Support\G7FunctionSpy;

    if (! function_exists(__NAMESPACE__.'\curl_setopt_array')) {
        /** @param array<int, mixed> $options */
        function curl_setopt_array(CurlHandle $handle, array $options): bool
        {
            G7FunctionSpy::$calls['curl_setopt_array'][] = $options;

            return \curl_setopt_array($handle, $options);
        }
    }

    if (! function_exists(__NAMESPACE__.'\stream_socket_client')) {
        /**
         * @param  resource|null  $context
         * @return resource|false
         */
        function stream_socket_client(string $address, mixed &$error_code = null, mixed &$error_message = null, ?float $timeout = null, int $flags = STREAM_CLIENT_CONNECT, $context = null)
        {
            G7FunctionSpy::$calls['stream_socket_client'][] = ['address' => $address, 'timeout' => $timeout];

            return \stream_socket_client($address, $error_code, $error_message, $timeout, $flags, $context);
        }
    }
}
