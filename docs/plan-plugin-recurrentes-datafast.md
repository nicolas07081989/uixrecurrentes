# Plan mega detallado — Plugin de cobros recurrentes Datafast para TheUIXstudio

## 0) Objetivo del plan

Construir un plugin WordPress propio para suscripciones mensuales con Datafast, separando claramente:

1. **Primer pago** (checkout + widget + confirmación),
2. **Tokenización** (`registrationId`),
3. **Recurrencia automática** (cron + endpoint de recurrentes),
4. **Operación interna** (panel admin, estados, reintentos, auditoría).

> Este plan está diseñado para ejecutarse por etapas con entregables verificables, minimizando riesgo de fraude, errores operativos y regresiones en el sitio.

---

## 1) Principios de arquitectura

- **Plugin desacoplado de la lógica de WooCommerce checkout** (puede convivir con WooCommerce, pero no depender de su motor para recurrencia).
- **API-first interna**: toda lógica crítica encapsulada en servicios PHP (no en templates).
- **Idempotencia por diseño**: evitar doble cobro por reintentos, timeouts o cron duplicado.
- **Seguridad por capas**:
  - sanitización/escapado,
  - nonces/capabilities,
  - no persistir PAN/CVV,
  - logs redactados.
- **Observabilidad mínima obligatoria**: cada intento de cobro deja trazabilidad completa (request/response resumidos, código, decisión).
- **Feature flags por etapa** para activar progresivamente en producción.

---

## 2) Estructura propuesta del plugin

```text
wp-content/plugins/theuix-datafast-recurring/
├─ theuix-datafast-recurring.php
├─ uninstall.php
├─ readme.txt
├─ composer.json (opcional)
├─ src/
│  ├─ Core/
│  │  ├─ Plugin.php
│  │  ├─ Activator.php
│  │  ├─ Deactivator.php
│  │  ├─ Scheduler.php
│  │  └─ Logger.php
│  ├─ Admin/
│  │  ├─ SettingsPage.php
│  │  ├─ SubscriptionsPage.php
│  │  └─ LogsPage.php
│  ├─ Front/
│  │  ├─ ShortcodeSubscribe.php
│  │  ├─ CheckoutController.php
│  │  └─ Assets.php
│  ├─ Domain/
│  │  ├─ Subscription.php
│  │  ├─ ChargeAttempt.php
│  │  └─ Plan.php
│  ├─ Repository/
│  │  ├─ SubscriptionRepository.php
│  │  ├─ ChargeAttemptRepository.php
│  │  └─ PlanRepository.php
│  ├─ Service/
│  │  ├─ DatafastClient.php
│  │  ├─ CheckoutService.php
│  │  ├─ VerificationService.php
│  │  ├─ TokenService.php
│  │  ├─ RecurringChargeService.php
│  │  ├─ RetryPolicyService.php
│  │  └─ StatusTransitionService.php
│  ├─ Http/
│  │  ├─ WebhookController.php (opcional)
│  │  ├─ ReturnController.php
│  │  └─ AdminActionController.php
│  └─ Support/
│     ├─ Money.php
│     ├─ ResultCodeClassifier.php
│     └─ DateClock.php
├─ templates/
│  ├─ subscribe-form.php
│  ├─ payment-result.php
│  └─ admin/
├─ assets/
│  ├─ css/
│  └─ js/
└─ migrations/
   ├─ 001_create_subscriptions.sql
   ├─ 002_create_charge_attempts.sql
   └─ 003_create_plans.sql
```

---

## 3) Modelo de datos definitivo (MVP+)

## 3.1 Tabla `wp_uix_subscriptions`

- `id` BIGINT PK
- `uuid` CHAR(36) UNIQUE
- `customer_wp_user_id` BIGINT NULL
- `full_name` VARCHAR(150)
- `email` VARCHAR(190)
- `cedula_ruc` VARCHAR(20)
- `plan_slug` VARCHAR(80)
- `plan_title` VARCHAR(150)
- `amount` DECIMAL(10,2)
- `currency` CHAR(3) DEFAULT `USD`
- `registration_id` VARCHAR(120) NULL
- `status` ENUM(`pending`,`active`,`payment_failed`,`past_due`,`suspended`,`cancelled`,`expired`)
- `checkout_id` VARCHAR(120) NULL
- `checkout_resource_path` VARCHAR(255) NULL
- `payment_brand` VARCHAR(50) NULL
- `last_transaction_id` VARCHAR(120) NULL
- `last_result_code` VARCHAR(20) NULL
- `last_result_description` VARCHAR(255) NULL
- `retry_count` SMALLINT DEFAULT 0
- `max_retries` SMALLINT DEFAULT 3
- `next_charge_at` DATETIME NULL
- `last_charge_at` DATETIME NULL
- `suspended_at` DATETIME NULL
- `created_at` DATETIME
- `updated_at` DATETIME

Índices:
- (`status`, `next_charge_at`)
- (`email`)
- (`registration_id`)
- (`plan_slug`, `status`)

## 3.2 Tabla `wp_uix_charge_attempts`

- `id` BIGINT PK
- `subscription_id` BIGINT FK
- `idempotency_key` VARCHAR(80) UNIQUE
- `kind` ENUM(`initial`,`recurring`,`manual_retry`)
- `requested_amount` DECIMAL(10,2)
- `currency` CHAR(3)
- `request_payload_redacted` LONGTEXT
- `response_payload_redacted` LONGTEXT
- `http_status` SMALLINT NULL
- `result_code` VARCHAR(20) NULL
- `result_description` VARCHAR(255) NULL
- `transaction_id` VARCHAR(120) NULL
- `decision` ENUM(`approved`,`declined`,`error`,`pending_review`)
- `created_at` DATETIME

Índices:
- (`subscription_id`, `created_at`)
- (`result_code`)
- (`decision`)

## 3.3 Tabla `wp_uix_plans`

- `id` BIGINT PK
- `slug` VARCHAR(80) UNIQUE
- `title` VARCHAR(150)
- `description` TEXT
- `amount` DECIMAL(10,2)
- `currency` CHAR(3)
- `billing_interval` ENUM(`monthly`)
- `is_active` TINYINT(1)
- `created_at` DATETIME
- `updated_at` DATETIME

---

## 4) Configuración administrativa (Settings)

## 4.1 Bloque credenciales primer pago
- `initial_entity_id`
- `initial_bearer_token`
- `initial_base_url` (`eu-test.oppwa.com`, `eu-prod.oppwa.com`)
- `initial_test_mode_enabled`

## 4.2 Bloque credenciales recurrentes
- `recurring_entity_id`
- `recurring_bearer_token`
- `recurring_base_url`
- `recurring_test_mode_enabled`

## 4.3 Políticas de negocio
- `default_max_retries` (ej. 3)
- `retry_strategy` (`D+1`, `D+3`, `D+7`)
- `charge_hour_utc` (ej. 13:00 UTC)
- `grace_period_days` (ej. 5)
- `auto_suspend_enabled`

## 4.4 Seguridad y cumplimiento
- `enable_df_additional_validations_script` (on por defecto)
- `log_retention_days` (ej. 180)
- `redact_pii_in_logs` (on)
- `enforce_tls12_notice` (info operativa)

---

## 5) Flujos funcionales (con criterios de aceptación)

## 5.1 Flujo A — Suscripción inicial

1. Usuario abre landing del plan (shortcode/bloque).
2. Completa formulario (nombre, email, cédula/RUC, plan).
3. Backend valida y crea registro `pending`.
4. Backend crea checkout `POST /v1/checkouts` con:
   - `createRegistration=true`,
   - parámetros customer + tax/cart,
   - `shopperResultURL` firmado.
5. Front carga widget `paymentWidgets.js?checkoutId=...`.
6. Se incluye `dfAdditionalValidations1.js` al final.
7. Usuario paga; redirección a retorno.
8. Backend verifica por `resourcePath`.
9. Si aprobado:
   - guarda `registrationId`,
   - estado `active`,
   - `next_charge_at = +1 mes`.
10. Si fallo:
   - estado `payment_failed`,
   - guarda razón.

**Aceptación**
- No se activa suscripción sin verificación server-to-server.
- No se guarda PAN/CVV.
- Cada resultado queda en `charge_attempts`.

## 5.2 Flujo B — Cobro recurrente automático

1. Evento cron selecciona suscripciones `active` vencidas (`next_charge_at <= now`).
2. Por cada una, genera `idempotency_key`.
3. Ejecuta `POST /v1/registrations/{registrationId}/payments` con:
   - `entityId` recurrente,
   - `paymentType=DB`,
   - `risk.parameters[USER_DATA1]=REPEATED`.
4. Evalúa `result.code`.
5. Si éxito:
   - `status=active`,
   - `retry_count=0`,
   - mueve `next_charge_at` +1 mes.
6. Si fallo recuperable:
   - `status=past_due`,
   - incrementa `retry_count`,
   - agenda próximo reintento según política.
7. Si excede reintentos:
   - `status=suspended`.

**Aceptación**
- Nunca dos cobros el mismo día para la misma suscripción + misma ventana lógica.
- Reintentos respetan política configurada.

## 5.3 Flujo C — Gestión admin

- Ver listado por estado.
- Ver próximo cobro, último código, retry_count.
- Acción manual: reintentar cobro.
- Acción manual: suspender/reactivar (sin cobrar).
- Export CSV básico.

**Aceptación**
- Solo usuarios con capability administrativa.
- Toda acción manual genera log auditable.

---

## 6) Mapeo de códigos de resultado y reglas

Crear `ResultCodeClassifier`:

- **SUCCESS**: `000.000.000`, `000.100.110`, `000.200.100`, `000.100.112`.
- **HARD_DECLINE**: `100.400.147`, `800.100.151`.
- **CUSTOMER_ABORT**: `800.100.152`.
- **UNKNOWN/ERROR**: resto.

Reglas:
- HARD_DECLINE: no reintentar automáticamente más de 1 vez (opcional 0).
- CUSTOMER_ABORT (solo inicial): permitir nueva sesión checkout.
- UNKNOWN: reintentar según política prudente.

---

## 7) Cron y operación

## 7.1 Eventos
- `uix_recurring_charge_runner` (cada hora, procesa vencidas por lotes).
- `uix_recurring_retry_runner` (diario, reintentos).
- `uix_recurring_maintenance` (diario, limpieza/rotación logs).

## 7.2 Estrategia por lotes
- Lote inicial: 25 suscripciones por ejecución.
- Lock transaccional por suscripción (`processing_lock_until`) para evitar carreras.
- Timeout por cobro (ej. 30s).

## 7.3 Recomendación productiva
- Usar cron real del servidor llamando `wp-cron.php` cada 5 min.

---

## 8) Seguridad detallada

- Nonce y `current_user_can()` en todas acciones admin.
- Sanitización estricta (`sanitize_text_field`, `sanitize_email`, regex cédula).
- Escape de salida (`esc_html`, `esc_attr`, etc.).
- Cifrado opcional de `registrationId` en DB (libsodium/OpenSSL con clave en `wp-config.php`).
- Nunca registrar bearer token completo.
- Logs redactados de PII sensible.
- Política de retención/borrado de logs.
- Checklist de salida a producción: TLS1.2+, escaneo y hardening.

---

## 9) Plan de implementación por sprints

## Sprint 0 — Descubrimiento técnico (3–5 días)

**Objetivo:** cerrar incógnitas con Datafast.

Tareas:
- Confirmar credenciales separadas inicial/recurrente.
- Confirmar parámetros mandatorios fase actual.
- Confirmar tarjetas de prueba y matriz de códigos esperados.
- Confirmar obligatoriedad exacta de `dfAdditionalValidations1.js` en tu tipo de integración.

Entregables:
- Documento de decisiones técnicas firmado.
- Matriz de configuración (test/prod).

## Sprint 1 — Esqueleto del plugin + DB (4–6 días)

Tareas:
- Bootstrap plugin (autoload, activación/desactivación).
- Migraciones DB (`subscriptions`, `charge_attempts`, `plans`).
- Página settings con validaciones.
- Logger básico.

Aceptación:
- Plugin instala/desinstala sin errores.
- Tablas creadas con índices.

## Sprint 2 — Primer pago + tokenización (6–8 días)

Tareas:
- Formulario de suscripción (shortcode).
- Checkout creation service.
- Render widget + script adicional de validación.
- Endpoint retorno y verificación por `resourcePath`.
- Persistencia de `registrationId`.

Aceptación:
- Caso feliz completo en test: `pending -> active`.
- Caso fallido: `pending -> payment_failed`.

## Sprint 3 — Recurrencia automática (6–8 días)

Tareas:
- Scheduler + lock por suscripción.
- Servicio de cobro recurrente por `registrations/{id}/payments`.
- Estrategia de reintentos y transiciones de estado.
- Registro completo en `charge_attempts`.

Aceptación:
- Cobro recurrente exitoso avanza `next_charge_at`.
- Fallo incrementa reintentos y cambia a `past_due`/`suspended`.

## Sprint 4 — Panel admin y operación (4–6 días)

Tareas:
- Listado de suscripciones con filtros.
- Vista detalle con historial de intentos.
- Acciones manuales (retry/suspend/reactivate).
- Export CSV.

Aceptación:
- Operación diaria posible sin tocar DB manualmente.

## Sprint 5 — Hardening + UAT + go-live (5–7 días)

Tareas:
- Tests end-to-end en ambiente QA.
- Prueba de carga controlada del cron.
- Revisión de seguridad (OWASP básico + checklist Datafast).
- Prueba real de $1 coordinada con Datafast.

Aceptación:
- Runbook operativo aprobado.
- Paso a producción con rollback plan.

---

## 10) Estrategia de pruebas (detallada)

## 10.1 Unitarias
- Clasificación de códigos (`ResultCodeClassifier`).
- Cálculo de próxima fecha de cobro.
- Política de reintentos.

## 10.2 Integración
- Cliente HTTP Datafast con mocks de respuesta.
- Repositorios DB (inserción/actualización/transiciones).

## 10.3 E2E funcional
- Alta suscripción completa con token.
- Cobro recurrente exitoso.
- Cobro recurrente fallido + reintento.
- Suspensión por max retries.

## 10.4 Pruebas operativas
- Doble ejecución de cron simultánea (verificar locks/idempotencia).
- Caída de red durante cobro (recovery).

---

## 11) Observabilidad y soporte

- Correlation ID por intento.
- Dashboard admin con KPIs:
  - suscripciones activas,
  - cobros del día,
  - tasa de aprobación,
  - fallos por código.
- Alertas (email/slack opcional) cuando:
  - tasa de fallo > umbral,
  - cron no corre,
  - errores consecutivos de API.

---

## 12) Riesgos y mitigaciones

1. **Parámetros faltantes Datafast**
   - Mitigación: Sprint 0 obligatorio + pruebas con casos reales.
2. **Credenciales recurrentes distintas**
   - Mitigación: settings separados + validadores previos.
3. **WP-Cron no confiable**
   - Mitigación: cron del servidor + health checks.
4. **Bloqueo antifraude por patrones anómalos**
   - Mitigación: ventanas de cobro controladas + coordinación previa producción.
5. **Doble cobro accidental**
   - Mitigación: idempotency key + locks + constraints DB.

---

## 13) Checklist de salida a producción

- [ ] Escaneo seguridad aprobado por Datafast.
- [ ] TLS1.2+ validado en servidor y dependencias.
- [ ] Credenciales productivas cargadas y testeadas.
- [ ] Script adicional de validaciones activo en checkout.
- [ ] Cron del servidor activo y monitorizado.
- [ ] Política de reintentos validada por negocio.
- [ ] Prueba de $1 coordinada con Datafast completada.
- [ ] Runbook y soporte de incidentes documentados.

---

## 14) Runbook operativo (resumen)

- **Incidente: muchos `past_due`**
  - revisar códigos de respuesta predominantes,
  - validar credenciales/restricciones antifraude,
  - pausar retries automáticos temporalmente.

- **Incidente: cron detenido**
  - ejecutar runner manual WP-CLI,
  - revisar jobs del sistema,
  - verificar lock huérfano.

- **Incidente: error masivo API**
  - circuit breaker temporal,
  - notificar operaciones,
  - reprogramar lote.

---

## 15) Definición de “Done” (DoD)

Se considera completado cuando:

1. Existe alta de suscripción con primer pago + tokenización en pruebas.
2. Existe cobro recurrente automático exitoso por cron.
3. Reintentos y suspensión funcionan según política.
4. Panel admin permite operar sin SQL manual.
5. Seguridad y logging cumplen requisitos mínimos.
6. Producción validada con transacción real coordinada.

---

## 16) Próximo paso inmediato recomendado

Ejecutar **Sprint 0** y no iniciar desarrollo del motor recurrente hasta cerrar:

- credenciales exactas (inicial vs recurrente),
- parámetros obligatorios faltantes que hoy causan errores,
- matriz de códigos/escenarios de prueba acordada con Datafast.

