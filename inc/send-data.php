<?php

/**
 * Envía datos a la API de AdCombo.
 *
 * @param array $order_data Datos del pedido.
 * @return array|WP_Error Respuesta de la API o un error.
 */
function send_data_to_adcombo_api( $order_data ) {
    $api_url_option = get_option('adcombo_api_url', '');

    if (empty($api_url_option)) {
        error_log('Error: La URL de la API de AdCombo no está configurada.');
        return new WP_Error('empty_url', 'La URL de la API de AdCombo no está configurada.');
    }

    if (filter_var($api_url_option, FILTER_VALIDATE_URL) === false) {
        error_log('Error: La URL de la API de AdCombo no es válida.');
        return new WP_Error('invalid_url', 'La URL de la API de AdCombo no es válida.');
    }

    $api_url = trailingslashit($api_url_option) . "order/create/";
    $query_string = http_build_query( $order_data );

    $response = wp_remote_get( $api_url . '?' . $query_string );

    // Verificar el código de estado HTTP
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code != 200 && $http_code != 201) {  // Aceptar tanto 200 como 201 como códigos de éxito
        error_log("Error HTTP al enviar datos a AdCombo: Código $http_code");
        return new WP_Error('http_error', "Error HTTP: $http_code");
    }

    return $response;
}

/**
 * Maneja la respuesta de la API de AdCombo.
 *
 * @param array|WP_Error $response Respuesta de la API o un error.
 * @param WC_Order $order Objeto del pedido de WooCommerce.
 * @param WC_Product $product Producto actual de WooCommerce.
 */

function handle_adcombo_api_response( $response, $order, $product ) {
    $order_id = $order->get_id();
    $product_name = $product->get_name();

    if ( is_wp_error( $response ) ) {
        $error_message = "Error al enviar datos del pedido $order_id a AdCombo: " . $response->get_error_message();
        error_log($error_message);
        $order->update_status('failed', $error_message);
        $order->add_order_note($error_message);
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if (!is_array($data)) {
        $error_message = "Respuesta de AdCombo para el pedido $order_id: " . $body;
        error_log($error_message);
        $order->update_status('failed', 'Error al procesar la respuesta de AdCombo.');
        $order->add_order_note($error_message);
        return;
    }

    if ( isset( $data['code'] ) && $data['code'] === 'ok' ) {
        update_post_meta($order_id, $product_name, $data['order_id']);
        $note_message = "ID del pedido Woocommerce: $order_id \n --- \n";
        $note_message .= "Respuesta de AdCombo para el producto <strong>$product_name</strong>:\n";
        $note_message .= "- Código: " . $data['code'] . "\n";
        $note_message .= "- Mensaje: " . $data['msg'] . "\n";
        $note_message .= "- ID del Pedido en AdCombo: " . $data['order_id'] . "\n";
        $note_message .= "- Es Duplicado: " . ($data['is_double'] ? "Sí" : "No") . "\n";
        $note_message .= "- Goods ID: " . $data['goods_id'] . "\n";
        $note_message .= "- Esub: " . $data['esub'] . "\n";
        if (isset($data['warning']) && is_array($data['warning'])) {
            $note_message .= "\n --- \n <strong>Advertencias:</strong>\n" . implode(", ", $data['warning']) . "\n";
        }

        $order->add_order_note($note_message);
        $order->update_status('on-hold', 'Pedido guardado con éxito en AdCombo.');
    } else {
        $error_message = "Error al guardar el pedido $order_id en AdCombo: " . $data['error'];
        error_log($error_message);
        $order->update_status('failed', $error_message);
        $order->add_order_note($error_message);
    }
}
