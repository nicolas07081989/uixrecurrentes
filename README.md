# UIX Recurrentes

Repositorio de trabajo para el desarrollo de soluciones de **cobros recurrentes con Datafast** en WordPress/WooCommerce para TheUIXstudio.

## Objetivo del repositorio

Este repo concentra:

- El plugin histórico/base de integración Datafast para WooCommerce.
- Un plugin en evolución enfocado en **suscripciones recurrentes**.
- Documentación de arquitectura y plan de implementación por etapas.

## Estructura del proyecto

- `theuix-datafast-recurring/`  
  Plugin principal en evolución para manejar tokenización, primer cobro y recurrencia.
- `pg-woocommerce-plugin-master/`  
  Implementación previa/legado del plugin de pagos Datafast en WooCommerce.
- `docs/`  
  Documentación técnica y planificación funcional.

## Estado actual

Actualmente el repositorio contiene código PHP para WordPress con foco en:

- Conexión con Datafast.
- Manejo de respuestas/códigos de resultado.
- Persistencia de datos de suscripciones/intentos.
- Componentes administrativos y de soporte para operación recurrente.

> Nota: este repositorio está en transición entre una base existente y una arquitectura más desacoplada para recurrencia.

## Requisitos de entorno

- PHP 7.4+ (recomendado 8.x si el entorno WordPress lo soporta).
- WordPress.
- WooCommerce.
- Credenciales de Datafast (sandbox o producción según entorno).

## Flujo sugerido de trabajo

1. Crear una rama por tarea (`feature/...`, `fix/...`, etc.).
2. Implementar cambios en el plugin objetivo (`theuix-datafast-recurring/` en nuevas funcionalidades).
3. Documentar decisiones técnicas en `docs/` cuando aplique.
4. Validar en entorno de pruebas con credenciales sandbox.
5. Abrir PR con resumen funcional, impacto técnico y pasos de validación.

## Documentación clave

- Plan funcional/técnico detallado:  
  `docs/plan-plugin-recurrentes-datafast.md`
- Contexto de plugins:
  - `theuix-datafast-recurring/README.md`
  - `pg-woocommerce-plugin-master/README.md`

## Próximos pasos recomendados

- Unificar criterios de logging y trazabilidad de intentos de cobro.
- Completar checklist de seguridad (redacción de datos sensibles, idempotencia y control de reintentos).
- Definir estrategia de versionado y releases del plugin recurrente.
- Incluir guía de QA con casos de éxito/fallo para primer pago y cobros recurrentes.

## Licencia

Pendiente de definición.
