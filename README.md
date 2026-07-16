# pagos-divididos-woo

Plugin de WooCommerce para checkout dividido en 3 pasos para Frankie Lencería.

## Instalación

1. Copiar este repositorio dentro de `wp-content/plugins/pagos-divididos-woo`.
2. Activar **Pagos Divididos Woo** desde el admin de WordPress.
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
- Paso 1 reutiliza WooCommerce (`order-pay`) para gateways estándar.
- Paso 2 usa pasarela específica configurable para credenciales/cuenta separada.
- El checkout estándar de WooCommerce no se modifica si el modo dividido está desactivado.
