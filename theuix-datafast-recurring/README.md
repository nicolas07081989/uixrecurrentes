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
