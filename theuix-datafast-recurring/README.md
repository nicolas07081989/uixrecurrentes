# TheUIX Datafast Recurring (MVP)

Plugin WordPress para:
- Suscripción inicial con widget Datafast.
- Tokenización (`registrationId`) en el primer pago.
- Cobro recurrente por cron usando `POST /v1/registrations/{registrationId}/payments`.
- Panel admin básico de configuración y suscripciones.

## Uso rápido
1. Activar plugin.
2. Configurar credenciales en **UIX Recurrentes**.
3. Agregar shortcode en una página:

```txt
[uix_subscribe_form plan="plan-pro" title="Plan Pro" amount="49.00"]
```

4. Ejecutar cron manualmente para pruebas:

```php
 do_action('uix_df_recurring_charge_runner');
```

## Solución de problemas de credenciales (entorno TEST)
Si `POST /v1/checkouts` devuelve `invalid authentication information`, revisa:

1. Que el par `Entity ID + Bearer Token` sea exactamente el entregado para checkout inicial.
2. Que las credenciales correspondan al endpoint correcto de Datafast (`eu-test.oppwa.com` o `test.oppwa.com`).
3. Que el token no esté revocado/expirado y tenga permisos para el canal de e-commerce.

Tip: en **UIX Recurrentes** puedes usar **"Probar credenciales"** para verificar rápidamente la autenticación sin pasar por todo el flujo de pago.

## Versión en un solo archivo
Si necesitas entregar el plugin como un único código completo, usa:

- `theuix-datafast-recurring/theuix-datafast-recurring-all-in-one.php`

Este archivo contiene todo el plugin (bootstrap + clases) en una sola pieza, sin dependencias de `includes/`.
