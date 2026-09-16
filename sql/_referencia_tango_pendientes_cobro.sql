/* ============================================================================
   REFERENCIA - NO SE EJECUTA EN LA APLICACION
   Pendiente de COBRO de clientes, tal como llego
   ----------------------------------------------------------------------------
   Base : central
   ----------------------------------------------------------------------------
   QUE ES ESTE ARCHIVO

   La consulta del pendiente de cobro de clientes, GUARDADA TAL CUAL SE RECIBIO.
   No la corre nadie: esta aca para poder contrastar contra ella la que usa el
   modulo, en Class/Ingresos.php -> getCobranzasMay().

   Es la hermana del lado de VENTAS de sql/_referencia_tango_pendientes.sql, que
   es la del lado de COMPRAS. Las dos resuelven el mismo problema -cuanto falta
   cobrar o pagar de un comprobante- con la misma tecnica: cruzar los
   vencimientos con las imputaciones.

   El guion bajo del nombre es a proposito: lo saca de la lista de scripts que
   hay que correr para poner el modulo en produccion.

   ----------------------------------------------------------------------------
   LO MAS IMPORTANTE DE TODO EL ARCHIVO: LA TABLA DE SIGNOS DE 'IMPU'

   La subconsulta IMPU suma, para cada vencimiento, todo lo que se imputo contra
   el. El signo de cada imputacion sale de que la imputo:

       T_COMP_CAN = 'REC'     -> RESTA   (recibo: el cliente pago)
       GVA15.TIPO_COMP = 'D'  -> SUMA    (nota de debito: deuda nueva)
       cualquier otro caso    -> RESTA   (recibos, notas de credito)

   Y el pendiente es:

       IMPORTE_PENDIENTE = SUM(GVA46.IMPORTE_VT + ISNULL(IMPU.IMPUTACIONES, 0))

   O sea: lo que se debia por cada vencimiento, mas las imputaciones ya
   firmadas. El ISNULL es necesario porque un vencimiento sin ninguna imputacion
   devuelve NULL del OUTER APPLY, y NULL adentro de una suma se lleva puesta la
   fila entera.

   EL 'REC' VA PRIMERO Y NO ES REDUNDANTE aunque el ELSE tambien reste: un
   recibo puede no tener fila en GVA15, y ahi GVA15.TIPO_COMP seria NULL. Sin la
   rama explicita, ese CASE anidado caeria igual en el ELSE y restaria, pero por
   accidente. Escrito asi, el recibo resta porque es un recibo.

   ----------------------------------------------------------------------------
   LAS TABLAS

       GVA12  Cabecera de comprobantes de ventas (facturas, NC, ND)
       GVA46  Vencimientos de cada comprobante. Un comprobante en cuotas tiene
              una fila por cuota, con su IMPORTE_VT y su ESTADO_VTO
       GVA07  Imputaciones: que comprobante cancelo a que vencimiento
       GVA15  Maestro de tipos de comprobante. TIPO_COMP = 'D' es debito
       GVA14  Maestro de CLIENTES. CLAUSULA es el flag de clausula de moneda
              extranjera, el mismo campo que CPA01.CLAUSULA del lado de compras

   ----------------------------------------------------------------------------
   QUE LE CAMBIO getCobranzasMay() Y POR QUE

   1. AGREGA GVA14.RAZON_SOCI al SELECT y al GROUP BY. La pantalla muestra la
      razon social y GVA14 ya estaba joineada; no se agrego ninguna columna mas
      que la que la pantalla usa.

   2. CASTea los dos importes a FLOAT. sqlsrv devuelve los decimal de SQL Server
      como string, y el resto del modulo trabaja con floats.

   3. Nada mas. El WHERE, el GROUP BY y la subconsulta IMPU van tal cual.

   ----------------------------------------------------------------------------
   LAS DECISIONES QUE ESTAN EN EL WHERE, Y QUE SON DECISIONES

   T_COMP = 'FAC'   SOLO FACTURAS, a proposito. La consulta anterior del modulo
                    traia tambien NDC/NDU/NC/NCC/NCU como filas propias. Las
                    notas de credito y debito IMPUTADAS ya estan descontadas del
                    pendiente por IMPU: traerlas ademas como filas las contaria
                    dos veces.

   COD_CLIENT
     LIKE 'M%'      Reemplaza al 'MA%' anterior. Verificado contra la base: no
                    hay ningun cliente 'M%' que no sea 'MA%', asi que da el
                    mismo conjunto y queda el filtro mas simple.

   CLAUSULA = 0     Deja afuera a los clientes con clausula de moneda
                    extranjera. VERIFICADO: de 1.165 clientes 'M%' hay 2 con
                    CLAUSULA = 1 -MAACCU y MAALLI- y ninguno de los dos tiene
                    comprobantes pendientes, de ningun tipo y de ninguna fecha.
                    El filtro hoy no deja afuera un solo peso de cartera. Si
                    alguno de esos dos empieza a operar, esta linea lo esconde.

   FECHA_EMIS >=
     hoy - 360 dias UN CORTE DELIBERADO, no una limitacion tecnica: una factura
                    de hace mas de un anio sin cobrar no es cobranza
                    proyectable. Verificado: hoy deja afuera dos facturas de
                    2017 por 44.652,05.

   ESTADO = 'PEN'   El comprobante sigue pendiente segun la cabecera...

   ESTADO_VTO
     <> 'PAG'       ...y ademas el vencimiento no esta marcado como pagado. Los
                    dos filtros no son el mismo: la cabecera puede quedar en
                    'PEN' con todas sus cuotas pagas.

   ----------------------------------------------------------------------------
   DOS COSAS QUE EL LECTOR VA A ENCONTRAR Y QUE NO SON ERRORES

   NULLIF(FECHA_EMIS, '18000101') puede devolver NULL: '18000101' es como Tango
   escribe "sin fecha". getCobranzasMay() lo saltea, porque sin fecha de emision
   no hay nada de donde proyectar la fecha de cobro.

   IMPORTE_PENDIENTE puede volver en CERO o NEGATIVO aunque el comprobante siga
   en ESTADO = 'PEN': IMPU resta todo lo imputado, asi que una factura
   sobre-imputada queda en negativo. getCobranzasMay() no las muestra ni las
   manda al eje -una factura sin saldo no es plata a cobrar- y deja UN aviso
   agregado por las negativas.

   ----------------------------------------------------------------------------
   LO QUE NO APORTA, Y POR ESO LA FECHA DE COBRO NO SALE DE ACA

   La consulta agrupa los vencimientos y devuelve UNA fila por comprobante, asi
   que GVA46.FECHA_VTO no sobrevive al GROUP BY: no hay una sola fecha de
   vencimiento que nombrar. La fecha de cobro del modulo sigue siendo
   FECHA_EMIS + el parametro 'cobranzas_may_dias_vto', con la fecha manual
   mandando por encima. Ver Ingresos::resolverFechaCobro().
   ============================================================================ */

SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED
SET DATEFORMAT DMY
SET DATEFIRST 7
SET DEADLOCK_PRIORITY -8;

SELECT
    GVA12.COD_CLIENT,
    NULLIF(GVA12.FECHA_EMIS, '18000101') AS FECHA_EMIS,
    GVA12.T_COMP,
    GVA12.N_COMP,
    GVA12.IMPORTE                                        AS IMPORTE_FACTURA,
    SUM(GVA46.IMPORTE_VT + ISNULL(IMPU.IMPUTACIONES, 0)) AS IMPORTE_PENDIENTE
FROM GVA12
INNER JOIN GVA46
        ON GVA46.T_COMP = GVA12.T_COMP
       AND GVA46.N_COMP = GVA12.N_COMP
INNER JOIN GVA14
        ON GVA14.COD_CLIENT = GVA12.COD_CLIENT
OUTER APPLY (
    SELECT SUM(CASE GVA07.T_COMP_CAN
                 WHEN 'REC' THEN -GVA07.IMPORT_CAN
                 ELSE CASE GVA15.TIPO_COMP
                        WHEN 'D' THEN  GVA07.IMPORT_CAN
                        ELSE          -GVA07.IMPORT_CAN
                      END
               END) AS IMPUTACIONES
    FROM GVA07
    LEFT JOIN GVA15 ON GVA15.IDENT_COMP = GVA07.T_COMP_CAN
    WHERE GVA07.T_COMP    = GVA12.T_COMP
      AND GVA07.N_COMP    = GVA12.N_COMP
      AND GVA07.FECHA_VTO = GVA46.FECHA_VTO
) AS IMPU
WHERE GVA12.T_COMP      = 'FAC'
  AND GVA12.FECHA_EMIS >= DATEADD(dd, -360, CAST(GETDATE() AS date))
  AND GVA12.COD_CLIENT LIKE 'M%'
  AND GVA14.CLAUSULA    = 0
  AND GVA12.ESTADO      = 'PEN'
  AND GVA46.ESTADO_VTO <> 'PAG'
GROUP BY
    GVA12.COD_CLIENT,
    GVA12.FECHA_EMIS,
    GVA12.T_COMP,
    GVA12.N_COMP,
    GVA12.IMPORTE;
