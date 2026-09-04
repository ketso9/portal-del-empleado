---
trigger: always_on
---

# Memoria Técnica Persistente en Obsidian

El proyecto cuenta con una base de conocimiento y memoria viva estructurada dentro de la bóveda de **Obsidian** accesible a través del servidor MCP `obsidian`.

## Ubicación de las Notas
* **Hub Principal:** `Proyectos/Portal del Empleado/Index.md`
* **Bitácora de Sesiones:** `Proyectos/Portal del Empleado/Bitacora.md`
* **Roadmap y Tareas:** `Proyectos/Portal del Empleado/Roadmap.md`
* **Despliegue y Operativa:** `Proyectos/Portal del Empleado/Despliegue.md`
* **Coordinación entre Agentes:** `Proyectos/Portal del Empleado/Sesiones.md`
* **Arquitectura Técnica:** `Proyectos/Portal del Empleado/Arquitectura/`
  * `Arquitectura-PDF-Signature.md`
  * `Integracion-Microsoft-Graph.md`
  * `Estructura-Submodulos.md`

## Directivas de Memoria para el Agente
1. **Consulta Previa:** Antes de implementar refactorizaciones complejas, añadir nuevos submódulos o modificar el pipeline de firma/autenticación, consultar la nota de arquitectura correspondiente en Obsidian (`call_mcp_tool` con `vault_read` o `search_simple`).
2. **Actualización de Bitácora:** Al finalizar hitos relevantes, correcciones críticas de bugs o cambios arquitecturales, registrar un resumen de los cambios en `Proyectos/Portal del Empleado/Bitacora.md` para mantener la continuidad entre sesiones.
3. **Mantenimiento del Roadmap:** Marcar como completadas o añadir nuevas tareas en `Proyectos/Portal del Empleado/Roadmap.md` según los requerimientos del usuario.
4. **Coordinación con los otros agentes.** Este vault lo comparten varios agentes (Antigravity y Claude Code) y **no tiene bloqueo de escritura**: dos agentes editando la misma nota a la vez se pisan sin dejar rastro. Por eso, **antes de escribir en el vault, leer `Proyectos/Portal del Empleado/Sesiones.md`**. Si otro agente tiene declarada esa nota, no escribir: avisar al usuario del conflicto y esperar su decisión. Al empezar una sesión de trabajo, añadir la fila propia a la tabla; al terminar, retirarla. Preferir siempre escrituras por sección antes que reescribir la nota completa.
5. **Espejo del protocolo.** Este fichero y `CLAUDE.md` (raíz del repositorio) son el mismo protocolo para dos agentes distintos. Si se cambia uno, hay que cambiar el otro.
6. **Transparencia con el usuario.** Siempre que se cree o modifique una nota en el vault (Bitácora, Roadmap, Sesiones o Arquitectura), se debe informar explícitamente al usuario en la respuesta del chat resumiendo el cambio realizado.

