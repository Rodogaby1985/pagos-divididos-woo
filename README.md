# pagos-divididos-woo

Plugin de WooCommerce para checkout dividido en 3 pasos para Frankie Lencería.

## Instalación

1. Copiar este repositorio dentro de `wp-content/plugins/pagos-divididos-woo`.
2. Activar **Pagos Divididos Woo** desde el panel de administración de WordPress.
3. Verificar que WooCommerce esté activo.
4. Crear una página e insertar el shortcode:
   - `[pdw_checkout_dividido]`

## Configuración (Admin)

Ruta: **WooCommerce > Checkout dividido**

- Activar/desactivar checkout dividido.
- Definir pasarela exclusiva para **pago de envío** (cuenta separada).
- Personalizar texto de aviso de Paso 1.
- Personalizar texto de aviso de Paso 2.
- Definir texto de contacto para casos de fallo en pago de envío.

## Flujo funcional (3 pasos)

### Paso 1: Pago de productos
- Captura datos del cliente.
- Crea orden de productos sin envío.
- Redirige al `order-pay` estándar de WooCommerce.
- Se usan gateways activos de WooCommerce, excluyendo la pasarela reservada para envío.
- Mensaje por defecto:
  - **“Estás pagando solo PRODUCTOS. El envío se paga en el siguiente paso.”**

### Paso 2: Pago de envío
- Calcula métodos de envío usando dirección guardada en la orden de productos.
- Crea una orden separada solo para el cobro de envío.
- Redirige al `order-pay` de esa orden.
- En este paso se fuerza únicamente la pasarela configurada para envío.
- Mensaje por defecto:
  - **“Estás pagando solo ENVÍO. Este cobro se procesa en una cuenta distinta.”**

### Paso 3: Resumen y confirmación
- Muestra separación de montos y estado:
  - Pago productos
  - Pago envío
- Confirmación final idempotente (no doble confirmación).

## Reglas de negocio implementadas

1. Si falla pago de productos (Paso 1), el flujo no avanza.
2. Si pago de productos OK y pago de envío falla:
   - Orden de productos en `on-hold`.
   - Nota visible de coordinación con texto de contacto configurado.
3. Si ambos pagos OK:
   - Orden final en `processing` o `completed` según `needs_processing()` de WooCommerce.

## Persistencia y seguridad

- Estado del flujo y pagos guardado como `order meta`:
  - `_pdw_products_payment_status`
  - `_pdw_shipping_payment_status`
  - `_pdw_shipping_order_id`
  - `_pdw_finalized`
  - metadatos de envío seleccionado y monto.
- Validaciones mínimas:
  - Nonces en acciones de formularios.
  - Evita recrear pago de envío si existe uno pendiente.
  - Evita doble confirmación final.

## Supuestos técnicos

- No se usa endpoint externo.
- Paso 1 llama directamente a `process_payment()` del gateway seleccionado (sin pasar por `order-pay`).
- Paso 2 usa pasarela específica configurable para credenciales/cuenta separada.
- El checkout estándar de WooCommerce no se modifica si el modo dividido está desactivado.

## Changelog

### v0.1.2 — HOTFIX URGENTE: Paso 1 no debe cerrar en `order-received` sin pago real

**Problema reportado:**  
En algunos intentos del Paso 1 ("PAGAR PRODUCTOS"), el flujo terminaba en `order-received`
sin una aprobación de pago real, mostrando avance incorrecto.

**Corrección aplicada:**

1. Validación estricta de respuesta en Paso 1:
   - Solo se redirige cuando `process_payment($order_id)` devuelve:
     - `is_array($result)`
     - `result === success`
     - `redirect` no vacío.
2. Sin fallback a thank-you:
   - No se usa fallback a `order-received`.
   - Si el gateway devuelve redirect hacia `order-received` pero la orden aún no está pagada,
     se rechaza y se informa error al usuario.
3. UX de error explícita:
   - Mensaje visible al usuario cuando falla inicio de pago:
     - _"No fue posible iniciar el pago de productos. Intenta nuevamente o contacta soporte."_
   - Mensaje explícito cuando no existe pasarela elegible para productos.
4. Logging de diagnóstico (fuente `pdw`):
   - `info` al iniciar pago y registrar resumen sanitizado de `process_payment`.
   - `error` cuando se rechaza la respuesta, con motivo (sin exponer secretos).

**Pruebas realizadas:**
- `php -l pagos-divididos-woo.php`

### v0.1.1 — HOTFIX: Flujo Paso 1 "PAGAR PRODUCTOS" (loop silencioso)

**Causa del bug:**  
Al crear la orden de productos, el plugin redirigía al endpoint estándar de WooCommerce
`/checkout/order-pay/{order_id}/`. Esa pantalla requiere que el usuario elija gateway y envíe
el formulario. Si el gateway devolvía un error o no había ninguno disponible, la página se
recargaba en silencio, generando un loop: la orden quedaba en `pending payment` y el usuario
no avanzaba.

**Corrección aplicada:**

1. Se reemplazó la redirección a `order-pay` por una llamada directa a `process_payment()`:
   - Se selecciona automáticamente el primer gateway activo en WooCommerce (excluyendo la
     pasarela reservada para envío).
   - Se asigna el gateway a la orden (`set_payment_method`) antes de procesarla.
   - Si `process_payment()` devuelve `success + redirect`, se redirige al usuario a la
     pasarela.
   - Si no hay gateway elegible, se muestra un error claro:
     _"No hay método de pago configurado para productos. Por favor, contactá al administrador."_
   - Si `process_payment()` falla o no devuelve redirect, se muestra un error accionable
     (sin recargar en silencio).

2. El botón "PAGAR PRODUCTOS" del estado pendiente pasa de ser un `<a>` (link a `order-pay`)
   a un formulario POST que vuelve a ejecutar el flujo de pago directo.

3. Se agregan logs WooCommerce (`WooCommerce > Estado > Logs`, fuente `pdw`) con:
   - `order_id`
   - Gateway elegido
   - Resultado de `process_payment()`
   - URL de redirect devuelta

## Habilitar logging para diagnóstico en producción

1. Ir a **WooCommerce > Estado > Logs**.
2. Filtrar por fuente: `pdw`.
3. Los registros incluyen nivel `info` (flujo normal) y `error` (problemas de gateway).
4. Para aumentar verbosidad de WooCommerce en general, podés agregar en `wp-config.php`:
   ```php
   define( 'WC_LOG_THRESHOLD', 'debug' );
   ```
   _(Recomendado solo en staging; deshabilitar en producción cuando no sea necesario.)_
