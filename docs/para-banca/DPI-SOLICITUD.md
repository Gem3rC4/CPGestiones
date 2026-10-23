# Para Banca Virtual: el DPI de la solicitud debe ser el del cliente con sesión

**Problema.** Al solicitar un producto, el formulario acepta cualquier DPI. Con un DPI ajeno, la solicitud se evaluaba en Analítica con datos de otra persona (resultado «No aprobada») y se enviaba a CP Gestiones.

**Corrección.** Parche [`dpi-solicitud.patch`](dpi-solicitud.patch). Toca solo `solicitudes.service.ts` y aplica sobre su último commit (`4460dfe`).

- En `crear()`, antes de llamar al CORE (Analítica), se compara el DPI del formulario con el de la ficha del cliente que tiene la sesión.
- Si no coinciden, la solicitud se detiene al instante con 422 y el mensaje: «El DPI ingresado no coincide con el de su cuenta. Por favor, ingrese su DPI correctamente».
- No se evalúa en Analítica, no se guarda nada y no se envía a CP Gestiones.

## Mensajes del registro (vienen de CP Gestiones, ya publicados)

Banca Virtual ya muestra el mensaje que devuelve CP, así que no requiere cambios:

| Caso | Mensaje |
| --- | --- |
| El DPI ya tiene una cuenta (acceso) creada | «El usuario ya tiene una cuenta creada.» (409 `CUSTOMER_EXISTS`, el mismo código que usan antes de cada gestión) |
| El DPI no existe en CP Gestiones | «El DPI no existe en la base de datos, digite uno válido.» (404) |
| El correo es de otro cliente | «Ese correo electrónico ya pertenece a otro cliente. Usa el correo que registraste en el banco.» |
