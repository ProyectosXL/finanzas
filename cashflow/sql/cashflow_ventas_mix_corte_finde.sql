
/* ============================================================================
   CASHFLOW - VENTAS: EL CORTE DE FIN DE SEMANA EN EL MIX DE COBRO
   ============================================================================
   Base    : central. Reejecutable.
   Orden   : DESPUES de sql/cashflow_ventas_mix_nodo.sql (agrega una columna a
             su tabla; sin ella corta con un PRINT y no hace nada) y EN EL
             MISMO MOMENTO de publicar el codigo de
             feature/ventas-corte-fin-de-semana. Ver README-ventas.md, "Orden
             de ejecucion de los scripts".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   La fecha de acreditacion de una venta era "dia de venta + dias de la hoja",
   corrida al proximo habil bancario. Con Fiserv a 1 dia, las ventas del
   viernes, el sabado y el domingo se proyectaban el LUNES, pero Fiserv las
   acredita el MARTES: el lote del fin de semana se presenta junto, y el
   plazo se cuenta desde el primer habil siguiente. (Test 86 del checklist.)

   Esto agrega a cada nodo del arbol el CORTE DE FIN DE SEMANA:

       CORTE_FINDE   NULL        lo define un nivel de arriba (se hereda como
                                 los dias: gana el nivel mas cercano que lo
                                 tenga; si nadie lo tiene, no aplica)
                     NO_APLICA   la venta se acredita a "dia + dias", como
                                 siempre. Es un NO explicito: corta la herencia
                     VIERNES     las ventas de viernes a domingo se toman como
                                 del primer habil despues del domingo, y desde
                                 ahi se cuentan los dias
                     SABADO      lo mismo, para las de sabado y domingo

   La regla vive en UN solo lugar: MixCobro::fechaAcreditacion(), en
   cashflow/Class/MixCobro.php. Este script no calcula nada.

   ES UN DATO FIJO DEL NODO: no se abre por mes. Las tablas del mix por mes
   (sql/cashflow_ventas_mix_mes.sql) no cambian.

   QUE HACE, EN ESTE ORDEN

     1. Agrega RO_T_CASHFLOW_VENTAS_MIX_NODO.CORTE_FINDE, si no existe.
     2. Agrega su CHECK, si no existe.
     3. Deja FISERV EN "DESDE EL VIERNES": todo nodo de nivel PROCESADORA que
        se llame Fiserv, en cualquier canal, activo o no (el corte es un dato
        del nodo y tiene que estar si se lo vuelve a encender). La comparacion
        es la de la columna -Modern_Spanish_CI_AI, sin mayusculas ni acentos-,
        la misma que MixCobro::nombreComparable(). SOLO DONDE CORTE_FINDE ES
        NULL: un valor ya cargado desde Parametros no se pisa. Sus cuotas lo
        heredan: no se tocan.
        Cada nodo tocado queda con USUARIO_MODIF = el nombre de este script y
        FECHA_MODIF = cuando corre, para que "Ultima edicion" diga que no lo
        cambio una persona. Imprime cada uno con su camino.

   No borra nada ni toca ninguna otra columna.

   SIN ESTE SCRIPT la pantalla no se cae: la cobranza se calcula como antes,
   sin corte, y Parametros muestra la columna "Corte finde" deshabilitada,
   diciendo que script falta.

   APENAS SE CORRE, LA COBRANZA DE FISERV Y SUS CUOTAS CAMBIA DE FECHA (las
   ventas de viernes a domingo pasan del lunes al martes, a 1 dia). El total
   del horizonte no cambia, salvo lo que cruza el borde del eje. Todo lo
   demas da igual al ultimo bit. Ver README-ventas.md, "El corte de fin de
   semana", donde esta medido.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. La tabla del arbol tiene que existir
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NULL
    PRINT 'Falta dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO: correr antes sql/cashflow_ventas_mix_nodo.sql. '
        + 'No se hizo nada.';
GO

/* ----------------------------------------------------------------------------
   1. La columna. NULL: las filas que ya existen heredan, o sea "no aplica"
      mientras nadie arriba lo cargue. No hay valor por defecto: un default
      seria un corte que nadie eligio en cada nodo nuevo.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'CORTE_FINDE') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO ADD CORTE_FINDE VARCHAR(10) NULL;
    PRINT 'Agregada RO_T_CASHFLOW_VENTAS_MIX_NODO.CORTE_FINDE.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_MIX_NODO.CORTE_FINDE ya existe (o falta la tabla): no se agrega.';
GO

/* ----------------------------------------------------------------------------
   2. El CHECK. LA LISTA Y MixCobro::CORTES CAMBIAN JUNTAS.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'CORTE_FINDE') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints
                   WHERE name = 'CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_CORTE')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO ADD CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_CORTE
        CHECK (CORTE_FINDE IS NULL OR CORTE_FINDE IN ('NO_APLICA', 'VIERNES', 'SABADO'));
    PRINT 'Creado CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_CORTE.';
END
ELSE
    PRINT 'CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_CORTE ya existe (o falta la columna): no se crea.';
GO

/* ----------------------------------------------------------------------------
   3. Fiserv en "Desde el viernes", sin pisar lo cargado.
      Va en SQL dinamico: la columna puede haber nacido en este mismo script,
      y un lote que la nombra no compila si cuando se lo lee todavia no existe.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'CORTE_FINDE') IS NOT NULL
BEGIN
    EXEC sp_executesql N'
        DECLARE @Usuario VARCHAR(50) = ''sql/cashflow_ventas_mix_corte_finde.sql'';
        DECLARE @Tocados TABLE (ID INT PRIMARY KEY);

        UPDATE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO
        SET CORTE_FINDE = ''VIERNES'',
            USUARIO_MODIF = @Usuario,
            FECHA_MODIF = GETDATE()
        OUTPUT INSERTED.ID INTO @Tocados
        WHERE NIVEL = ''PROCESADORA''
          AND LTRIM(RTRIM(NOMBRE)) = ''Fiserv''
          AND CORTE_FINDE IS NULL;

        IF NOT EXISTS (SELECT 1 FROM @Tocados)
        BEGIN
            PRINT ''Ningun Fiserv sin corte cargado: no se toco nada.'';

            IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO
                       WHERE NIVEL = ''PROCESADORA'' AND LTRIM(RTRIM(NOMBRE)) = ''Fiserv'')
                PRINT ''(Los Fiserv que hay ya tienen su corte: se respeta lo cargado.)'';
            ELSE
                PRINT ''(No hay ningun nodo Procesadora llamado Fiserv.)'';
        END
        ELSE
        BEGIN
            -- El camino de cada nodo tocado, para el PRINT: Canal > ... > Fiserv.
            -- Subiendo de padre en padre hasta el primer nivel.
            DECLARE @Lineas TABLE (ID INT PRIMARY KEY, TEXTO NVARCHAR(400));

            WITH Camino AS (
                SELECT n.ID AS ID_TOCADO, n.ID_PADRE, CAST(n.NOMBRE AS NVARCHAR(400)) AS TEXTO,
                       n.CANAL, n.ACTIVO
                FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO n
                JOIN @Tocados t ON t.ID = n.ID
                UNION ALL
                SELECT c.ID_TOCADO, p.ID_PADRE, CAST(p.NOMBRE + N'' > '' + c.TEXTO AS NVARCHAR(400)),
                       c.CANAL, c.ACTIVO
                FROM Camino c
                JOIN dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO p ON p.ID = c.ID_PADRE
            )
            INSERT INTO @Lineas (ID, TEXTO)
            SELECT ID_TOCADO,
                   N''  Corte "Desde el viernes" en el nodo '' + CAST(ID_TOCADO AS NVARCHAR(10)) + N'': ''
                   + CANAL + N'' > '' + TEXTO
                   + CASE WHEN ACTIVO = 1 THEN N'''' ELSE N'' (inhabilitado)'' END
            FROM Camino
            WHERE ID_PADRE IS NULL;

            DECLARE @Id INT = (SELECT MIN(ID) FROM @Lineas);
            DECLARE @Linea NVARCHAR(400);

            WHILE @Id IS NOT NULL
            BEGIN
                SELECT @Linea = TEXTO FROM @Lineas WHERE ID = @Id;
                PRINT @Linea;
                SET @Id = (SELECT MIN(ID) FROM @Lineas WHERE ID > @Id);
            END

            PRINT CAST((SELECT COUNT(*) FROM @Tocados) AS VARCHAR(10))
                + '' nodo(s) Fiserv quedaron en "Desde el viernes". Sus cuotas lo heredan.'';
        END';
END
ELSE
    PRINT 'Sin la columna CORTE_FINDE no se carga ningun corte.';
GO

PRINT 'Listo.';
GO
