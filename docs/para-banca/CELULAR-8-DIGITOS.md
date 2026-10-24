# Para Banca Virtual: el celular de 8 dígitos no se aceptaba

**Problema.** Al registrarse, un celular válido como `88556633` mostraba «Escribe un celular válido de 8 dígitos».

**Causa.** La validación era `/^[2-7]\d{7}$/`: exige que el **primer dígito sea del 2 al 7**, así que cualquier número que empiece con 8, 9, 0 o 1 se rechaza aunque tenga 8 dígitos. La misma regla estaba copiada en 8 lugares.

**Corrección.** Parche [`celular-8-digitos.patch`](celular-8-digitos.patch), sobre su último commit (`01463df`). La regla pasa a `/^\d{8}$/`: exactamente 8 dígitos numéricos, sin espacios ni otros caracteres.

| Archivo | Uso |
| --- | --- |
| `frontend/.../app/registro/page.tsx` | Registro |
| `frontend/.../app/(banca)/perfil/page.tsx` | Guardar celular en el perfil |
| `frontend/.../app/(banca)/transferencias/page.tsx` (2) | Transferencia por celular |
| `backend/.../auth/auth.schema.ts` | Registro (API) |
| `backend/.../clientes/clientes.schema.ts` | Perfil (API) |
| `backend/.../beneficiarios/beneficiarios.schema.ts` | Beneficiarios (API) |
| `backend/.../transferencias/transferencias.schema.ts` | Transferencia por celular (API) |

Comprobación de la regla: `88556633`, `55513002` y `12345678` se aceptan; `8855663` (7 dígitos), `885566333` (9) y `8855 633` (con espacio) se rechazan. El backend compila con el cambio.
