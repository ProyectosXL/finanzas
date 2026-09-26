/* ============================================================================
   MODULO CASHFLOW - FILA DEL NETEO DE CHEQUES ADELANTADOS
   Agrega la fila NETEO_PRECHEQUEADO a la seccion VENTAS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_estructura_disponibilidades.sql
            (es el script que crea la seccion VENTAS)
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Hay clientes que entregan los echeqs ANTES de que se les facture: esa venta
   futura ya esta cobrada, asi que proyectarla de nuevo la contaria dos veces.
   Lo que hay que restar sale de Echeqs -> Venta Cobrada Anticipada y lo calcula
   Ventas::getNeteoPrechequeado().

   HASTA AHORA ese neteo se restaba ADENTRO de las series de cobranza del
   proveedor VENTAS: la serie COBRANZA y las cuatro COBRANZA_<CANAL> salian ya
   netas, y la unica forma de saber cuanto se habia neteado era abrir la pestana
   Ventas. Ahora las series volvieron a BRUTO y el neteo sale por su propia
   serie, que es lo que alimenta esta fila.

   El motivo del cambio es que el neteo es informacion que el tablero tiene que
   mostrar, no una correccion que tenga que esconder: quien lee el cuadro ve la
   cobranza proyectada, ve cuanto de eso ya estaba cobrado, y ve el neto.

   POR QUE TIPO = 'INGRESO' CON IMPORTE NEGATIVO, Y NO 'EGRESO'
   Un egreso es plata que sale. Esto no es plata que sale: es cobranza que no va
   a entrar porque ya entro. Ponerlo como EGRESO lo mostraria en el cuadro como
   un pago, y ademas el motor le daria signo -1 a un importe que YA viene
   negativo, con lo cual el neteo terminaria SUMANDO a la cobranza.

   VentasProvider entrega la serie NETEO_PRECHEQUEADO con los importes en
   negativo -da vuelta el signo en VentasProvider::enNegativo()- y el motor suma
   los ingresos tal cual vienen. Un ingreso negativo resta. Es la unica
   combinacion que da el resultado correcto.

   ES CRITICO QUE LAS SERIES DE COBRANZA SIGAN SIENDO BRUTAS. Si alguien volviera
   a netear adentro de COBRANZA -o de las series por canal- teniendo esta fila
   activa, el neteo se restaria DOS VECES y no hay ninguna validacion que lo
   detecte: las dos series son legitimas por separado. Esta escrito tambien en
   el encabezado de cashflow/Class/Providers/VentasProvider.php.

   ORDEN = 25
   Queda entre Franquicias (20) y Mayoristas (30). Hoy TODO el neteo es de
   franquicias -son las que operan con pre-chequeado- asi que la fila queda
   justo debajo de la fila que corrige, que es donde se la busca. La serie, en
   cambio, lleva el total de TODOS los canales: si maniana un mayorista entrega
   cheques por adelantado, su neteo entra en esta misma fila sin tocar nada.
   Si eso llegara a pasar y la ubicacion se volviera confusa, se corrige el
   ORDEN desde Parametros -> Estructura, sin tocar codigo ni volver a correr
   este script.

   COMPUTA = 1: la fila entra en el subtotal "Total Ventas". Es el punto: el
   subtotal de la seccion tiene que dar la cobranza NETA, que es la que
   efectivamente va a entrar.

   ES REEJECUTABLE: va con MERGE sobre CODIGO -que es UNIQUE- asi que una
   segunda corrida no duplica la fila. En una base donde la fila ya existe
   tampoco pisa el NOMBRE ni el ORDEN: si alguien la movio o la renombro desde
   Parametros -> Estructura, esa edicion se respeta. Lo unico que reafirma es el
   ORIGEN, que es lo que la ata al proveedor y no es editable a mano sin romper
   la fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* La seccion VENTAS la crea cashflow_estructura_disponibilidades.sql. Sin ella
   la fila quedaria huerfana -no se dibujaria en ningun lado- asi que en vez de
   insertarla igual, el script avisa y no hace nada. */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'VENTAS')
BEGIN
    PRINT 'FALTA la seccion VENTAS: corre antes sql/cashflow_estructura_disponibilidades.sql. No se hizo nada.';
END
ELSE
BEGIN
    MERGE dbo.RO_T_CASHFLOW_CONF_FILA AS T
    USING (VALUES
        ('NETEO_PRECHEQUEADO', 'Neteo cheques adelantados', 'VENTAS', 'INGRESO', 1,
            'VENTAS', 'NETEO_PRECHEQUEADO', 25)
    ) AS S (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN)
        ON T.CODIGO = S.CODIGO
    WHEN MATCHED THEN
        /* Solo el origen: NOMBRE y ORDEN pueden haberse editado desde la
           pantalla de estructura y esa edicion vale mas que esta semilla. */
        UPDATE SET ORIGEN_PROVIDER = S.ORIGEN_PROVIDER,
                   ORIGEN_SERIE = S.ORIGEN_SERIE,
                   FECHA_UPDATE = GETDATE()
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
        VALUES (S.CODIGO, S.NOMBRE, S.SECCION, S.TIPO, S.COMPUTA,
                S.ORIGEN_PROVIDER, S.ORIGEN_SERIE, S.ORDEN, 1);

    PRINT 'Fila NETEO_PRECHEQUEADO lista en la seccion VENTAS.';
END
GO
