# Portal del Empleado — Instrucciones para Claude Code

Responder siempre en castellano.

## Memoria Técnica Persistente en Obsidian

La memoria viva del proyecto **no vive en este repositorio**: está en una bóveda de
Obsidian que se comparte con los demás agentes (Gemini / Antigravity). Se accede por
el servidor MCP `obsidian` (`obsidian-local-rest-api`, `http://127.0.0.1:27123`).

Esta nota es el espejo de `.agent/rules/obsidian_memory.md`, que es el protocolo
equivalente de Antigravity. **Las dos deben decir lo mismo**: si se cambia el
protocolo, hay que actualizar ambas.

### Ubicación de las notas

* **Hub principal:** `Proyectos/Portal del Empleado/Index.md`
* **Bitácora de sesiones:** `Proyectos/Portal del Empleado/Bitacora.md`
* **Roadmap y tareas:** `Proyectos/Portal del Empleado/Roadmap.md`
* **Despliegue y operativa:** `Proyectos/Portal del Empleado/Despliegue.md`
* **Coordinación entre agentes:** `Proyectos/Portal del Empleado/Sesiones.md`
* **Arquitectura técnica:** `Proyectos/Portal del Empleado/Arquitectura/`
  * `Arquitectura-PDF-Signature.md`
  * `Integracion-Microsoft-Graph.md`
  * `Estructura-Submodulos.md`

### Directivas

1. **Consulta previa.** Antes de refactorizaciones complejas, de añadir submódulos o
   de tocar el pipeline de firma/autenticación, leer la nota de arquitectura
   correspondiente. Antes de subir nada a los entornos, leer `Despliegue.md`.
2. **Actualización de bitácora.** Al cerrar un hito, corregir un bug crítico o tomar
   una decisión arquitectural, registrar un resumen en `Bitacora.md`, para mantener la
   continuidad entre sesiones y entre agentes.
3. **Mantenimiento del roadmap.** Marcar tareas completadas y añadir las nuevas en
   `Roadmap.md`.
4. **Coordinación con los otros agentes.** El vault no tiene bloqueo de escritura: dos
   agentes editando la misma nota a la vez se pisan sin dejar rastro. Por eso, **antes
   de escribir en el vault, leer `Sesiones.md`**. Si otro agente tiene declarada esa
   nota, no escribir: avisar al usuario del conflicto y esperar su decisión. Al empezar
   una sesión de trabajo, añadir la fila propia; al terminar, retirarla. Preferir
   siempre escrituras por sección (`vault_append`, `vault_patch`) antes que reescribir
   la nota completa.
5. **Fuente única de verdad.** No duplicar en la memoria local de Claude lo que ya
   está en el vault: como mucho, un puntero. Si algo se contradice, manda el vault.
6. **Si el vault no responde**, casi siempre es que Obsidian no está abierto: el
   plugin solo escucha mientras la aplicación de escritorio está en ejecución.
   Comprobar eso antes de tocar configuración.
7. **Transparencia con el usuario.** Siempre que se cree o modifique una nota en el
   vault (Bitácora, Roadmap, Sesiones o Arquitectura), se debe informar explícitamente al
   usuario en la respuesta del chat resumiendo el cambio realizado.


## Convenciones del proyecto

* Plugin de WordPress monolítico modular. Los submódulos van en `plugins/<nombre>` e
  implementan obligatoriamente `interface-ep-app.php`.
* Todo lo del empleado final se resuelve en **frontend** (shortcodes, AJAX o REST):
  el empleado no entra en `wp-admin`.
* Mantener la compatibilidad del pipeline **FPDI 2.1.7 + TCPDF** en `ep-signature/libs/`.
* Seguridad: sanitización estricta, verificación de nonces y comprobación de permisos
  vía `class-ep-security.php` y `class-ep-roles.php`.
* **Versionado:** con cada cambio se sube la versión de parche del plugin (2.0.1 → 2.0.2).
  Solo se salta a una versión mayor si el usuario lo pide y dice a qué numeración.
  (Espejo de `plugins/ep-signature/libs/.agent/rules/version.md`.)
