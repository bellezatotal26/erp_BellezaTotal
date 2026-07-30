Conectores de facturación electrónica (Seri ERP)
================================================

Proveedores: Alegra, Siigo, Factus y DIAN (directo/esqueleto).

IMPORTANTE — Multi-tenant
- Las credenciales NO van en el .env global (mezclaría empresas).
- Se guardan cifradas por tenant en la tabla integration_settings.
- Configúrelas en Contabilidad → Integraciones (formulario en pantalla).

Alegra
- Email + token API (Basic Auth)
- Docs: https://developer.alegra.com/

Siigo
- username + access_key → JWT en POST /auth
- Header Partner-Id obligatorio (por defecto SeriERP)
- Docs: https://developers.siigo.com/

Factus
- OAuth2 password grant: client_id, client_secret, username, password
- Sandbox: https://api-sandbox.factus.com.co
- Docs: https://developers.factus.com.co/

DIAN directo
- Requiere ser Proveedor Tecnológico autorizado o usar factura gratuita DIAN.
- Seri guarda NIT, resolución, clave técnica, software_id y PIN.
- La emisión UBL/CUFE/WS queda como fase posterior.

Cifrado
- Tokens se cifran con AES-256-GCM (storage/app.key o APP_KEY en .env).
