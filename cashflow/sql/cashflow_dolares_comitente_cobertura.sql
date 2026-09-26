/* ============================================================================
   MODULO CASHFLOW - DOLARES CUENTA COMITENTE PASA A SER STOCK DE COBERTURA
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_cobertura.sql, que es el que crea la
             seccion COBERTURA.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   Los dolares de la cuenta comitente entraban al flujo como INGRESO en la fecha
   de su carga. Eso decia que ese dia INGRESA plata, y no es cierto: los dolares
   YA ESTAN en la cuenta. Lo que hay que decidir es CUANDO se los usa, y esa
   decision se carga en la seccion Cobertura.

   Es exactamente el mismo movimiento que ya hizo el saldo de inversiones en
   sql/cashflow_cobertura.sql, y este script lo copia paso por paso:

     - la fila vieja -seccion DISPONIBILIDADES, tipo INGRESO- se da de baja
       LOGICA y se renombra para que se entienda por que quedo apagada;
     - se crea una fila nueva en COBERTURA, tipo STOCK_COBERTURA y COMPUTA = 0.

   ----------------------------------------------------------------------------
   POR QUE FUNCIONA SIN TOCAR EL MOTOR

   Cashflow::calcularTotales() SUMA TODAS las filas de tipo STOCK_COBERTURA para
   saber con cuanto se puede cubrir. No conoce a ninguna por su codigo, asi que
   los dolares se suman a las inversiones y el aviso de "se aplica mas cobertura
   de la que hay" los cuenta solo.

   El motor ademas le vacia las columnas de fecha a toda fila de ese tipo y
   muestra el importe unicamente en la columna Total: un stock no ocurre un dia,
   esta. Por eso COMPUTA = 0 -no entra en ninguna suma del flujo- y por eso la
   plata recien se mueve cuando alguien aplica cobertura, que es la fila
   USO_COBERTURA.

   ----------------------------------------------------------------------------
   EL SALDO ES LA ULTIMA CARGA, NO LA SUMA

   Cada carga de la pantalla es una FOTO del saldo a esa fecha. Al escribir esto
   hay dos -66.000 y 71.000 dolares- que la serie vieja sumaba como si fueran
   137.000 dolares juntos en la cuenta. La serie STOCK se queda con la mas
   reciente, que es el saldo.

   No hay dato que migrar: las cargas quedan como estan y cambia quien las lee.

   ----------------------------------------------------------------------------
   LA FECHA DE CRONOGRAMA QUEDA INERTE

   FECHA_CRONOGRAMA decidia en que dia del eje se dibujaba cada importe. Un stock
   no se dibuja en ninguna columna, asi que esa fecha dejo de tener efecto y la
   pantalla dejo de ofrecerla. La columna NO SE BORRA: queda por el mismo motivo
   que la serie INGRESO queda declarada en el registro, para poder volver atras
   sin migrar datos.

   ----------------------------------------------------------------------------
   PARA VOLVER ATRAS

   Poner ACTIVO = 1 en DOLARES_COMITENTE y ACTIVO = 0 en STOCK_DOLARES_COMITENTE,
   desde Parametros y sin tocar codigo. OJO: las dos juntas muestran el mismo
   dinero dos veces, y el validador lo rechaza porque el registro relaciona las
   series STOCK e INGRESO en 'componentes'.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'COBERTURA')
BEGIN
    RAISERROR('Falta la seccion COBERTURA. Corre primero sql/cashflow_cobertura.sql.', 16, 1);
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE CODIGO = 'STOCK_DOLARES_COMITENTE')
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    /* La fila nueva. ORDEN 15: entre las inversiones (10) y el uso (20), asi que
       los dos stocks quedan juntos y arriba de la fila que los aplica, que es el
       orden en el que se lee la seccion. */
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('STOCK_DOLARES_COMITENTE', 'Dolares en cuenta comitente', 'COBERTURA',
         'STOCK_COBERTURA', 0, 'DOLARES_COMITENTE', 'STOCK', 15, 1);

    /* La vieja deja de ser un ingreso. Baja LOGICA: reactivarla es poner el bit
       en 1. Se renombra para que quien la vea apagada en Parametros entienda por
       que, en lugar de reactivarla y duplicar el saldo. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0,
        NOMBRE = 'Dolares Cuenta Comitente (pasó a ser stock de cobertura)',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'DOLARES_COMITENTE';

    COMMIT TRANSACTION;

    PRINT 'Dolares Cuenta Comitente movido a la seccion Cobertura.';
END
ELSE
BEGIN
    PRINT 'La fila STOCK_DOLARES_COMITENTE ya existe: no se hizo nada.';
END
GO

/* ----------------------------------------------------------------------------
   Control: las dos filas no pueden estar activas a la vez.

   Si esto avisa, alguien reactivo la vieja desde Parametros sin desactivar la
   nueva. El validador de estructura lo rechaza igual -las series estan
   relacionadas en 'componentes'- pero el aviso dice donde mirar.
   ---------------------------------------------------------------------------- */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE CODIGO = 'DOLARES_COMITENTE' AND ACTIVO = 1)
   AND EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE CODIGO = 'STOCK_DOLARES_COMITENTE' AND ACTIVO = 1)
BEGIN
    RAISERROR('ATENCION: las dos filas de dolares estan activas y muestran el mismo dinero dos veces. Dejá una sola.', 16, 1);
END
GO
