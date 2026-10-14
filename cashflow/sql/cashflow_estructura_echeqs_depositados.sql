/* ============================================================================
   MODULO CASHFLOW - FILA "ECHEQS DEPOSITADOS"
   Agrega la fila ECHEQS_DEPOSITADOS a la seccion DISPONIBILIDADES
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_estructura_disponibilidades.sql (crea la
            seccion) y de sql/cashflow_echeqs_deposito.sql (crea el deposito).
            Sin esta fila NO SE PUEDE DEPOSITAR: el boton queda apagado
            nombrando este script.
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Un cheque de cartera que se marca como depositado ya no entra en la fecha
   del cheque: entra a las 48 hs habiles del deposito, y por el NETO de las
   retenciones del banco. Ver README-echeqs.md.

   Por eso sale de "Echeqs en cartera" -la serie A_COBRAR deja de traerlo- y
   entra en esta fila, ECHEQS -> DEPOSITADOS: la suma de los netos de los
   depositos que se proyectan, por fecha de acreditacion.

   ES CRITICO QUE LAS DOS ESTEN ACTIVAS JUNTAS. Son dos partes distintas del
   mismo universo y no se pisan: cada cheque esta en una sola. Con A_COBRAR sola,
   lo depositado no entra en ningun lado y el tablero informa de menos SIN
   AVISAR: cada serie es correcta por separado. Por eso Echeqs no deja depositar
   mientras esta fila no este activa, y la pestana avisa si hay depositos y la
   fila falta.

   LA SERIE TOTAL A_COBRAR_TODO las incluye a las dos -y a los excluidos-, asi
   que activarla al lado de cualquiera de ellas lo rechaza el validador de
   Parametros -> Cashflow, como hasta ahora.

   ORDEN = 25
   Queda entre "Echeqs en cartera" (20) y "Cobranzas Pagos Electronicos" (30):
   pegada a la fila de la que salen sus cheques, que es donde se la busca.

   TIPO = 'INGRESO' Y COMPUTA = 1: es plata que entra al banco y forma parte del
   subtotal "Total Disponibilidades".

   ES REEJECUTABLE: va con MERGE sobre CODIGO -que es UNIQUE- asi que una
   segunda corrida no duplica la fila. En una base donde la fila ya existe
   tampoco pisa el NOMBRE ni el ORDEN: si alguien la movio o la renombro desde
   Parametros -> Cashflow, esa edicion se respeta. Lo unico que reafirma es el
   ORIGEN, que es lo que la ata al proveedor.

   EL DIA QUE SE CORRE EL TABLERO NO SE MUEVE NI UN PESO: todavia no hay ningun
   deposito, asi que la fila nace en cero y A_COBRAR vale lo mismo que antes.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* La seccion DISPONIBILIDADES la crea cashflow_estructura_disponibilidades.sql.
   Sin ella la fila quedaria huerfana -no se dibujaria en ningun lado- asi que
   en vez de insertarla igual, el script avisa y no hace nada. */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'DISPONIBILIDADES')
BEGIN
    RAISERROR('FALTA la seccion DISPONIBILIDADES: corre antes sql/cashflow_estructura_disponibilidades.sql. No se hizo nada.', 16, 1);
END
ELSE
BEGIN
    MERGE dbo.RO_T_CASHFLOW_CONF_FILA AS T
    USING (VALUES
        ('ECHEQS_DEPOSITADOS', 'Echeqs depositados', 'DISPONIBILIDADES', 'INGRESO', 1,
            'ECHEQS', 'DEPOSITADOS', 25)
    ) AS S (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN)
        ON T.CODIGO = S.CODIGO
    WHEN MATCHED THEN
        /* Solo el origen: NOMBRE y ORDEN pueden haberse editado desde la
           pantalla de estructura y esa edicion vale mas que esta semilla. */
        UPDATE SET ORIGEN_PROVIDER = S.ORIGEN_PROVIDER,
                   ORIGEN_SERIE = S.ORIGEN_SERIE,
                   FECHA_MODIF = GETDATE()
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
        VALUES (S.CODIGO, S.NOMBRE, S.SECCION, S.TIPO, S.COMPUTA,
                S.ORIGEN_PROVIDER, S.ORIGEN_SERIE, S.ORDEN, 1);

    PRINT 'Fila ECHEQS_DEPOSITADOS lista en la seccion DISPONIBILIDADES.';
END
GO

/* ----------------------------------------------------------------------------
   Control: como queda la cartera en el tablero.

   Lo esperado es "Echeqs en cartera" con A_COBRAR y "Echeqs depositados" con
   DEPOSITADOS, las dos activas, y ninguna con A_COBRAR_TODO.
   ---------------------------------------------------------------------------- */
SELECT ORDEN, CODIGO, NOMBRE, ORIGEN_SERIE, ACTIVO
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE ORIGEN_PROVIDER = 'ECHEQS'
ORDER BY ORDEN;
GO

IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'ECHEQS' AND ORIGEN_SERIE = 'A_COBRAR_TODO' AND ACTIVO = 1)
BEGIN
    RAISERROR('ATENCION: hay una fila activa con ECHEQS -> A_COBRAR_TODO. Ese total incluye a los depositados: junto a "Echeqs depositados" contaria dos veces el mismo cheque. Dejala inactiva desde Parametros -> Cashflow.', 16, 1);
END
GO
