/* ============================================================================
   REFERENCIA - NO SE EJECUTA EN LA APLICACION
   Consulta de pendientes de pago tal como la genera Tango
   ----------------------------------------------------------------------------
   Base : central
   ----------------------------------------------------------------------------
   QUE ES ESTE ARCHIVO

   Es la consulta que Tango arma para su reporte de composicion de saldos de
   proveedores, GUARDADA TAL CUAL SE RECIBIO. No la corre nadie: esta aca para
   poder contrastar contra ella la consulta depurada que si usa el modulo, en
   Class/Proveedores.php.

   El guion bajo del nombre es a proposito: lo saca de la lista de scripts que
   hay que correr para poner el modulo en produccion.

   POR QUE NO SE USA TAL CUAL

   1. Filtra CPA54.FECHA_VTO >= hoy, con lo que TODO LO VENCIDO DESAPARECE. Es
      lo contrario de lo que el tablero tiene que hacer: una factura vencida
      impaga es plata que igual hay que pagar. La depurada no lleva ese filtro.

   2. Arrastra joins que este listado no usa: CPA57 y CPA108 (provincia y pais),
      SUCURSAL y la subconsulta de NRO_SUCURS, GVA81 (clasificacion),
      CPA_CONTACTOS_PROVEEDOR_HABITUAL y un LEFT JOIN EMPRESA ON 1=1.

   3. El CASE 'BICLAUSU' es una constante que Tango resuelve al generar la
      consulta: de las tres ramas solo se evalua la de 'BICLAUSU'. Reducido, eso
      significa:
          Total Pendiente (CTE) = pendiente EN PESOS de los proveedores SIN
                                  clausula (CPA01.CLAUSULA <> 1)
          Total Pendiente (EXT) = el de los proveedores CON clausula, en
                                  unidades de la moneda extranjera
      La depurada deja ese filtro escrito y comentado, no heredado de un CASE
      muerto.

   4. La columna IMPORTETOTAL de la subconsulta TMP no se usa en el SELECT
      final: es codigo muerto. Ademas su CASE de signos esta incompleto -si
      CRE_DEB es NULL y el cancelador no es 'O/P', devuelve NULL y el SUM lo
      saltea-, a diferencia del de la subconsulta IMPU, que si se usa y si esta
      completo.

   LO QUE SI HAY QUE COPIAR DE ACA, Y ES LO MAS IMPORTANTE

   El signo con el que cada imputacion afecta al pendiente, en la subconsulta
   IMPU. CPA05 no guarda solo pagos: guarda TODO lo que se imputa contra el
   comprobante, y no todo lo cancela.

       T_COMP_CAN = 'REC'        -> RESTA (aunque CPA21 diga que REC es 'D')
       CPA21.CRE_DEB = 'D'       -> SUMA   (una nota de debito es deuda NUEVA)
       cualquier otro caso       -> RESTA  (O/P, notas de credito)

   'O/P' no figura en CPA21 -es una orden de pago, no un comprobante de
   compras-, asi que cae en el ELSE y resta, que es lo correcto.

   LA RAMA DE 'REC' ES CODIGO NO EJERCITADO. Se copio tal cual por fidelidad al
   origen, pero NO SE PUDO VERIFICAR CON DATOS: en CPA05 hay 109.314
   imputaciones y NINGUNA tiene T_COMP_CAN = 'REC' -son todas O/P, NC*, ND* o
   AJU-. Si algun dia aparece una, sera la primera vez que esa rama corra. Se
   deja porque Tango la declara explicitamente y sobreescribe el CRE_DEB de
   CPA21 -que dice 'D' para REC-, lo cual solo tiene sentido si el caso existe
   en alguna instalacion.

   Sin esa tabla de signos, una nota de debito imputada se resta como si fuera
   un pago y el pendiente da NEGATIVO. Pasa de verdad: ver el caso de
   DONNA DI DIO en README-proveedores-locales.md.

   NOTA SOBRE EL JOIN CPA04 <-> CPA54
   Tango los une por (COD_PROVEE, T_COMP, N_COMP). La depurada usa ID_CPA04, que
   es la clave subrogada y esta poblada en las tres tablas. Se verifico que las
   dos formas devuelven exactamente el mismo conjunto.
   ============================================================================ */

SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED
SET DATEFORMAT DMY
SET DATEFIRST 7
SET DEADLOCK_PRIORITY -8;

SELECT
    CASE WHEN TMP.fecha_cont = '01/01/1800' THEN NULL ELSE TMP.fecha_cont END AS [Fecha contable],
    TMP.T_COMP AS [Tipo de comprobante],
    TMP.N_COMP AS [Nro. Comprobante],
    CPA01.COD_PROVEE AS [Cód. proveedor],
    CPA01.NOM_FANT AS [Nombre comercial],
    CASE WHEN TMP.FECHA_VTO = '01/01/1800' THEN NULL ELSE TMP.FECHA_VTO END AS [Fecha de vencimiento],
    CASE 'BICLAUSU'
        WHEN 'BIMONCTE' THEN SUM(CASE WHEN (TMP.ESTADO_VTO <> 'PAG') OR (TMP.ESTADO_UNIDADES <> 'PAG') THEN TMP.IMPORT_VTO + ISNULL(IMPUTACIONES, 0) END)
        WHEN 'BICLAUSU' THEN SUM(CASE WHEN CPA01.CLAUSULA = 1 THEN 0 ELSE TMP.IMPORT_VTO + ISNULL(IMPUTACIONES, 0) END)
        ELSE 0
    END AS [Total Pendiente (CTE)],
    NOM_PROVEE AS [Razón social],
    CASE 'BICLAUSU'
        WHEN 'BIORIGEN' THEN SUM(CASE WHEN (TMP.ESTADO_VTO <> 'PAG') OR (TMP.ESTADO_UNIDADES <> 'PAG') THEN TMP.IMPORTE_VENCIMIENTO_UNIDADES + ISNULL(IMPUTACIONES_UNI, 0) ELSE 0 END)
        WHEN 'BICOTIZ' THEN SUM(CASE WHEN TMP.ESTADO_VTO <> 'PAG' THEN
                CASE CPA01.CLAUSULA
                    WHEN 1 THEN (TMP.IMPORTE_VENCIMIENTO_UNIDADES + ISNULL(IMPUTACIONES_UNI, 0))
                    ELSE CASE IMPUTACIONES_UNI
                            WHEN -TMP.IMPORTE_VENCIMIENTO_UNIDADES THEN (TMP.IMPORT_VTO + ISNULL(IMPUTACIONES, 0)) / CASE 1 WHEN 0 THEN 1 ELSE 1 END
                            ELSE (TMP.IMPORTE_VENCIMIENTO_UNIDADES + ISNULL(IMPUTACIONES_UNI, 0)) * TMP.COTIZACION / CASE 1 WHEN 0 THEN 1 ELSE 1 END
                         END
                END
            WHEN TMP.ESTADO_UNIDADES <> 'PAG' THEN (IMPORT_VTO + IMPUTACIONES) * TMP.COTIZACION / CASE 1 WHEN 0 THEN 1 ELSE 1 END
            ELSE 0 END)
        WHEN 'BICLAUSU' THEN SUM(CASE WHEN TMP.ESTADO_UNIDADES <> 'PAG' THEN
                CASE CPA01.CLAUSULA WHEN 1 THEN TMP.IMPORTE_VENCIMIENTO_UNIDADES + ISNULL(IMPUTACIONES_UNI, 0) ELSE 0 END
            ELSE 0 END)
        ELSE 0
    END AS [Total Pendiente (EXT)]
FROM
(
    SELECT
        CPA04.T_COMP,
        CPA04.N_COMP,
        CPA04.FECHA_CONT,
        CPA04.COD_PROVEE,
        CPA54.ESTADO_VTO,
        CPA54.ESTADO_UNIDADES,
        CPA54.IMPORTE_VENCIMIENTO_UNIDADES,
        CPA04.COTIZACION,
        CASE WHEN CPA04.NRO_SUCURS <> 0 THEN CPA04.NRO_SUCURS
             ELSE (SELECT SUC.NRO_SUCURSAL FROM EMPRESA JOIN SUCURSAL SUC ON (EMPRESA.ID_SUCURSAL = SUC.ID_SUCURSAL))
        END AS NRO_SUCURS,
        CPA54.FECHA_VTO,
        CPA54.IMPORT_VTO + ISNULL((
            SELECT SUM(CASE WHEN CPA21.CRE_DEB = 'D' THEN (+1)
                            WHEN CPA21.CRE_DEB = 'C' THEN (-1)
                            WHEN CPA05.T_COMP_CAN = 'O/P' THEN (-1) END * (CPA05.IMPORT_CAN)) AS IMPUTACIONES
            FROM CPA05
            LEFT JOIN CPA21 ON CPA21.T_COMP = CPA05.T_COMP_CAN
            WHERE CPA05.COD_PROVEE = CPA54.COD_PROVEE
              AND CPA05.T_COMP_FAC = CPA54.T_COMP
              AND CPA05.N_COMP_FAC = CPA54.N_COMP
              AND CPA05.FECHA_VTO = CPA54.FECHA_VTO), 0) AS IMPORTETOTAL,
        CPA54.IMPORT_VTO,
        MON_CTE,
        COD_CLASIF
    FROM CPA04
    INNER JOIN CPA54 ON CPA04.COD_PROVEE = CPA54.COD_PROVEE
                    AND CPA54.N_COMP = CPA04.N_COMP
                    AND CPA54.T_COMP = CPA04.T_COMP
    WHERE CPA04.ESTADO = 'PEN'
      AND CPA54.ESTADO_VTO <> 'PAG'
      AND CPA54.FECHA_VTO >= CAST(CAST(CAST(GETDATE() AS REAL) AS INT) AS DATETIME)
) AS TMP
LEFT JOIN CPA01 ON TMP.COD_PROVEE = CPA01.COD_PROVEE
LEFT JOIN CPA57 ON CPA01.PROVINCIA = CPA57.COD_PROVIN
LEFT JOIN CPA108 ON CPA57.COD_PAIS = CPA108.COD_PAIS
LEFT JOIN SUCURSAL ON TMP.NRO_SUCURS = SUCURSAL.NRO_SUCURSAL
LEFT JOIN GVA81 ON TMP.COD_CLASIF = GVA81.COD_CLASIF
LEFT JOIN CPA21 ON TMP.T_COMP = CPA21.T_COMP
LEFT JOIN CPA_CONTACTOS_PROVEEDOR_HABITUAL ON CPA_CONTACTOS_PROVEEDOR_HABITUAL.COD_PROVEE = CPA01.COD_PROVEE
                                          AND CPA_CONTACTOS_PROVEEDOR_HABITUAL.DEFECTO = 'S'
LEFT JOIN (
    SELECT
        CPA05.COD_PROVEE,
        CPA05.T_COMP_FAC,
        CPA05.N_COMP_FAC,
        CPA05.FECHA_VTO,
        SUM(CASE CPA05.T_COMP_CAN WHEN 'REC' THEN -(CPA05.IMPORT_CAN)
                 ELSE CASE CPA21.CRE_DEB WHEN 'D' THEN +(CPA05.IMPORT_CAN)
                                         ELSE -(CPA05.IMPORT_CAN) END
            END) AS IMPUTACIONES,
        SUM(CASE CPA05.T_COMP_CAN WHEN 'REC' THEN -(CPA05.IMPORTE_CANCELADO_UNIDADES)
                 ELSE CASE CPA21.CRE_DEB WHEN 'D' THEN +(CPA05.IMPORTE_CANCELADO_UNIDADES)
                                         ELSE -(CPA05.IMPORTE_CANCELADO_UNIDADES) END
            END) AS IMPUTACIONES_UNI
    FROM CPA05
    LEFT JOIN CPA21 ON CPA21.T_COMP = CPA05.T_COMP_CAN
    GROUP BY CPA05.COD_PROVEE, CPA05.T_COMP_FAC, CPA05.N_COMP_FAC, CPA05.FECHA_VTO
) AS IMPU ON IMPU.COD_PROVEE = TMP.COD_PROVEE
         AND IMPU.T_COMP_FAC = TMP.T_COMP
         AND IMPU.N_COMP_FAC = TMP.N_COMP
         AND IMPU.FECHA_VTO = TMP.FECHA_VTO
LEFT JOIN EMPRESA ON 1 = 1
GROUP BY
    TMP.FECHA_CONT,
    TMP.T_COMP,
    TMP.N_COMP,
    CPA01.COD_PROVEE,
    CPA01.NOM_FANT,
    TMP.FECHA_VTO,
    CPA01.NOM_PROVEE
