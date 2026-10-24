# Para Banca Virtual: el celular de 8 dígitos no se aceptaba

**Problema.** Al registrarse, celulares como `88556633` o `99999999` mostraban «Escribe un celular válido de 8 dígitos».

**Causa.** La validación era `/^[2-7]\d{7}$/`: exige que el **primer dígito sea del 2 al 7**, así que cualquier número que empiece con 8, 9, 0 o 1 se rechaza aunque tenga 8 dígitos. La misma regla estaba copiada en 8 lugares.

**Corrección.** Parche [`celular-8-digitos.patch`](celular-8-digitos.patch), sobre su último commit (`01463df`). La regla pasa a `/^\d{1,8}$/`: cualquier número, solo dígitos y **máximo 8** (el campo ya no deja escribir más de 8). Los mensajes dicen «Escribe solo números, máximo 8 dígitos».

| Archivo | Uso |
| --- | --- |
| `frontend/.../app/registro/page.tsx` | Registro |
| `frontend/.../app/(banca)/perfil/page.tsx` | Guardar celular en el perfil |
| `frontend/.../app/(banca)/transferencias/page.tsx` (2) | Transferencia por celular |
| `backend/.../auth/auth.schema.ts` | Registro (API) |
| `backend/.../clientes/clientes.schema.ts` | Perfil (API) |
| `backend/.../beneficiarios/beneficiarios.schema.ts` | Beneficiarios (API) |
| `backend/.../transferencias/transferencias.schema.ts` | Transferencia por celular (API) |

Comprobación de la regla: `99999999`, `88556633`, `1234` y `0` se aceptan; `123456789` (9 dígitos), `12 34` (con espacio) y vacío se rechazan. El backend compila con el cambio y el parche aplica sobre su último commit.
