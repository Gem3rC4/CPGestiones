# Para Banca Virtual: cliente al que el banco le cambió el correo

En CP Gestiones el banco ya puede corregir el correo de un cliente, aunque ese cliente use Banca Virtual. Desde ese momento, el cliente inicia sesión con el **correo nuevo** (CP valida correo y contraseña).

**Problema sin el parche.** Al iniciar sesión, `anclaLocal` busca la ficha local **por correo**. Con el correo nuevo no la encuentra e intenta crear otra con el mismo DPI. Eso choca con el índice único de `dpi_hash` y el inicio de sesión falla. Si no fallara, el cliente quedaría en una ficha nueva sin sus cuentas.

**Corrección.** Parche [`correo-cambiado.patch`](correo-cambiado.patch), sobre su último commit (`01463df`). Toca solo `auth/auth.service.ts`.

- Si no hay ficha con ese correo, se busca por **DPI**. El DPI no cambia: CP lo devuelve en cada inicio de sesión y en el registro.
- Si la encuentra, le pone el correo nuevo y sigue con esa misma ficha: el cliente conserva sus cuentas, movimientos, tarjetas y notificaciones.
- Solo aplica a clientes (rol `CLIENTE`), nunca a administradores.

El DPI de un cliente que ya usa Banca Virtual no se puede cambiar en CP Gestiones, justamente porque es el enlace entre los dos sistemas.
