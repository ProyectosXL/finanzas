/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: LISTAS DE OPCIONES ADMINISTRABLES
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : SEGUNDO de los dos scripts de esta entrega, y despues de
             sql/cashflow_prov_locales.sql, del que toma la semilla.
             Antes: sql/cashflow_comex_cotiz_edit.sql
   ----------------------------------------------------------------------------
   QUE RESUELVE

   RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS, PLAZO_PAGO y CRITERIO_DISTRIB eran
   TEXTO LIBRE. El formulario de carga manual ofrecia un datalist armado con los
   valores ya cargados -ProveedoresCategorias::rubrosCargados()- pero era una
   sugerencia: se podia escribir cualquier cosa.

   Y una de las cinco no es cosmetica: CADA RUBRO_ECONOMICO DISTINTO CREA UNA
   SERIE PROPIA EN EL TABLERO (ver ProveedoresCategorias::serieDeRubro()). Tipear
   "Alquileres " con un espacio al final no es un typo: es una fila nueva del
   cuadro que nadie pidio, que ademas hay que ir a configurar a Parametros para
   que se vea.

   El caso ya esta documentado en el propio modulo: la planilla real tiene
   '50% ECOMMERC' contra '50% ECOMMERCE', dos filas contra doscientas, y hoy eso
   se detecta a posteriori comparando parecidos -criteriosSospechosos()- porque
   no habia ninguna lista declarada contra la cual validar.

   Ahora hay cinco listas, y las administra el usuario desde Parametros.

   ----------------------------------------------------------------------------
   UNA SOLA TABLA CON UNA COLUMNA TIPO, Y NO CINCO TABLAS

   Las cinco listas tienen exactamente la misma forma -un valor, un orden, una
   vigencia- y exactamente el mismo ABM. Cinco tablas serian cinco CREATE, cinco
   consultas y cinco pantallas identicas que hay que mantener sincronizadas, y
   la primera que se olvide de un cambio queda distinta sin que nadie lo note.

   El CHECK sobre TIPO es lo que hace que la columna no sea texto libre: un typo
   en un INSERT a mano crearia una sexta lista invisible que ninguna pantalla
   dibuja.

   LAS CINCO LISTAS SON INDEPENDIENTES ENTRE SI. RUBRO no depende de
   RUBRO_ECONOMICO: no hay jerarquia, no hay padre, y elegir un rubro economico
   no acota los rubros disponibles. Si algun dia hiciera falta, la columna
   nueva seria un ID_PADRE nullable y no una tabla mas.

   ----------------------------------------------------------------------------
   PLAZO GUARDA EL TEXTO Y SU INTERPRETACION EN DIAS

   Es la unica de las cinco que tiene un significado que el sistema USA:
   ProveedoresCategorias::plazoEnDias() y la jerarquia de resolucion de fecha de
   pago trabajan con los dias, no con el texto.

       CONTADO   -> 0      se paga el dia de la factura
       '30 DIAS' -> 30
       DEBITO    -> NULL   se debita solo; la fecha no la decide un plazo

   PLAZO_DIAS NULL NO ES LO MISMO QUE 0. Cero es "se paga hoy" y NULL es "este
   plazo no dice cuando": el primero se usa para calcular una fecha y el segundo
   hace caer la jerarquia al escalon siguiente. Es la misma distincion que ya
   documenta la tabla del maestro, y perderla cambiaria la fecha de pago de
   todos los proveedores con DEBITO.

   Guardarlo en la lista -y no derivarlo siempre del texto- es lo que permite
   que administracion declare un plazo que plazoEnDias() no sabria interpretar,
   como 'FIN DE MES' -> 30. Cuando el valor no esta en la lista, plazoEnDias()
   sigue siendo el fallback y nada cambia.

   Las otras cuatro listas dejan PLAZO_DIAS en NULL. Podria ser una tabla aparte
   solo para PLAZO, pero seria la sexta pantalla identica a las otras cinco por
   una sola columna nullable.

   ----------------------------------------------------------------------------
   CRITERIO_DISTRIB ES, POR AHORA, SOLO UN NOMBRE

   Se verifico antes de escribir esto: en todo el modulo, CRITERIO_DISTRIB se
   guarda, se muestra en la grilla, se compara en el diff y se audita por typos.
   NINGUN CALCULO DEPENDE DE EL: no define porcentajes por canal ni afecta a
   ninguna serie del tablero. Esta lista lo normaliza y nada mas. El dia que
   tenga que repartir un gasto entre canales, los porcentajes son columnas
   nuevas de esta misma tabla.

   ----------------------------------------------------------------------------
   BAJA LOGICA, NUNCA FISICA

   Es el criterio del modulo entero. Un valor dado de baja deja de ofrecerse en
   el formulario pero NO desaparece de los proveedores que ya lo tienen: esos
   siguen con su valor y la pantalla los marca como fuera de lista. Borrar la
   fila dejaria proveedores apuntando a un valor que ya no se puede explicar.

   ----------------------------------------------------------------------------
   LA SEMILLA SALE DEL MAESTRO, NO DE UNA LISTA INVENTADA

   Es la misma leccion que este modulo ya aprendio con FORMAS_PAGO: la primera
   version de esa lista se escribio a ojo -CHEQUE, EFECTIVO, RETENCION...- y
   ninguno de esos cinco existia en el maestro real, mientras que los que si
   existian y faltaban eran el 54% de los proveedores.

   Asi que las cinco listas se siembran con los valores DISTINTOS que ya estan
   cargados en el maestro vigente, ordenados POR FRECUENCIA DE USO: lo que se
   usa doscientas veces va arriba y lo que se uso una vez va al final, que es
   donde se nota que probablemente sea un typo.

   Los valores se siembran TAL COMO ESTAN GUARDADOS, sin corregir mayusculas ni
   espacios. Corregirlos aca cambiaria en silencio la serie del tablero de los
   proveedores que los tienen. Lo que hay que arreglar se arregla despues, desde
   la pantalla, viendo el antes y el despues.

   ES REEJECUTABLE: reejecutarlo no duplica ni pisa nada editado. Los valores
   que ya estan en la tabla no se vuelven a insertar, y los que alguien dio de
   baja NO se reactivan: una baja es una decision y la semilla no la revierte.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_CATEG. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La tabla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES (
        ID           INT IDENTITY(1,1) NOT NULL,

        /* Cual de las cinco listas. El CHECK es lo que hace que no sea texto
           libre: un typo crearia una lista invisible que ninguna pantalla
           dibuja. */
        TIPO         VARCHAR(20)  NOT NULL,

        /* El valor, tal como se guarda en el maestro. MISMO LARGO que las
           columnas del maestro -VARCHAR(60)- a proposito: una lista que admita
           valores mas largos que la columna donde se guardan deja elegir algo
           que despues se trunca al escribir. */
        VALOR        VARCHAR(60)  NOT NULL,

        /* En que posicion se ofrece. La semilla lo llena por frecuencia de uso
           y despues lo reordena el usuario: en una lista de veintipico, que lo
           mas usado este arriba es la diferencia entre elegir y buscar. */
        ORDEN        INT          NOT NULL CONSTRAINT DF_RO_T_CF_PLOPC_ORDEN DEFAULT (0),

        /* Baja LOGICA. Un valor de baja deja de ofrecerse pero no desaparece de
           los proveedores que ya lo tienen. */
        VIGENTE      BIT          NOT NULL CONSTRAINT DF_RO_T_CF_PLOPC_VIGENTE DEFAULT (1),

        /* Solo para TIPO = 'PLAZO'. Ver la nota del encabezado: NULL no es 0. */
        PLAZO_DIAS   INT          NULL,

        FECHA_ALTA   DATETIME     NOT NULL CONSTRAINT DF_RO_T_CF_PLOPC_ALTA DEFAULT (GETDATE()),
        FECHA_BAJA   DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_OPCIONES PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_PLOPC_TIPO CHECK (TIPO IN
            ('RUBRO_ECONOMICO', 'RUBRO', 'CENTRO_COSTOS', 'PLAZO', 'CRITERIO_DISTRIB')),

        /* Un plazo negativo correria la fecha de pago hacia atras del
           comprobante, que es lo contrario de un plazo. */
        CONSTRAINT CK_RO_T_CF_PLOPC_DIAS CHECK (PLAZO_DIAS IS NULL OR PLAZO_DIAS >= 0)
    );

    PRINT 'Tabla RO_T_CASHFLOW_PROV_LOCALES_OPCIONES creada.';
END
ELSE
BEGIN
    PRINT 'La tabla RO_T_CASHFLOW_PROV_LOCALES_OPCIONES ya existia.';
END
GO

/* ----------------------------------------------------------------------------
   2. Unicidad por (TIPO, VALOR).

   INCLUYE LAS DADAS DE BAJA, y es deliberado: si el indice filtrara por
   VIGENTE, agregar un valor que ya existe de baja insertaria un duplicado en
   lugar de reactivarlo, y la lista terminaria con dos filas del mismo valor -una
   vigente y otra no- que ninguna pantalla puede distinguir. Reactivar es lo
   correcto, y el codigo lo hace.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = 'UQ_RO_T_CF_PLOPC_TIPO_VALOR'
          AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES')
   )
BEGIN
    /* Si hubiera duplicados de una carga previa, el indice falla y el script se
       corta. Se limpian antes, conservando el de ID mas bajo -el primero que se
       cargo- y dando de baja el resto en lugar de borrarlo. */
    ;WITH dup AS (
        SELECT ID, ROW_NUMBER() OVER (PARTITION BY TIPO, VALOR ORDER BY ID) AS N
        FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
    )
    DELETE FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
    WHERE ID IN (SELECT ID FROM dup WHERE N > 1);

    IF @@ROWCOUNT > 0
        PRINT 'ATENCION: se quitaron opciones duplicadas por (TIPO, VALOR).';

    CREATE UNIQUE INDEX UQ_RO_T_CF_PLOPC_TIPO_VALOR
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES (TIPO, VALOR);

    PRINT 'Indice unico por (TIPO, VALOR) creado.';
END
GO

/* ----------------------------------------------------------------------------
   3. La semilla: los valores que YA estan cargados en el maestro vigente.

   ORDEN sale de la frecuencia de uso: lo mas usado arriba. Se suma el maximo
   ORDEN que ya tenga la lista, asi que reejecutar el script despues de haber
   agregado valores a mano no los reordena ni los pisa.

   EL NOT EXISTS ES LO QUE LO HACE IDEMPOTENTE, y tambien lo que hace que un
   valor dado de baja NO se reactive: la fila existe, asi que no se vuelve a
   insertar. Una baja es una decision del usuario y la semilla no la revierte.
   ---------------------------------------------------------------------------- */
DECLARE @tipos TABLE (TIPO VARCHAR(20), COLUMNA VARCHAR(30));

INSERT INTO @tipos (TIPO, COLUMNA) VALUES
    ('RUBRO_ECONOMICO',  'RUBRO_ECONOMICO'),
    ('RUBRO',            'RUBRO'),
    ('CENTRO_COSTOS',    'CENTRO_COSTOS'),
    ('PLAZO',            'PLAZO_PAGO'),
    ('CRITERIO_DISTRIB', 'CRITERIO_DISTRIB');

/* Se recorre con un cursor y no con un INSERT por tipo repetido cinco veces:
   son cinco consultas identicas salvo por el nombre de la columna, y cinco
   copias del mismo SQL se desincronizan en el primer cambio. */
DECLARE @tipo VARCHAR(20), @col VARCHAR(30), @sql NVARCHAR(MAX), @base INT;

DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT TIPO, COLUMNA FROM @tipos;
OPEN cur;
FETCH NEXT FROM cur INTO @tipo, @col;

WHILE @@FETCH_STATUS = 0
BEGIN
    SELECT @base = ISNULL(MAX(ORDEN), 0)
    FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
    WHERE TIPO = @tipo;

    /* @col sale de la lista literal de arriba, no de ninguna entrada externa.
       @tipo va como parametro. */
    SET @sql = N'
        INSERT INTO dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES (TIPO, VALOR, ORDEN, VIGENTE)
        SELECT @t,
               v.VALOR,
               @b + ROW_NUMBER() OVER (ORDER BY v.USOS DESC, v.VALOR),
               1
        FROM (
            SELECT LTRIM(RTRIM(' + QUOTENAME(@col) + N')) AS VALOR, COUNT(*) AS USOS
            FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG
            WHERE VIGENTE = 1
              AND ' + QUOTENAME(@col) + N' IS NOT NULL
              AND LTRIM(RTRIM(' + QUOTENAME(@col) + N')) <> ''''
            GROUP BY LTRIM(RTRIM(' + QUOTENAME(@col) + N'))
        ) v
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES o
            WHERE o.TIPO = @t AND o.VALOR = v.VALOR
        );';

    EXEC sp_executesql @sql, N'@t VARCHAR(20), @b INT', @t = @tipo, @b = @base;

    PRINT 'Sembrado ' + @tipo + ': ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + ' valor(es) nuevos.';

    FETCH NEXT FROM cur INTO @tipo, @col;
END

CLOSE cur;
DEALLOCATE cur;
GO

/* ----------------------------------------------------------------------------
   4. Los dias de cada PLAZO, con el MISMO criterio que
      ProveedoresCategorias::plazoEnDias().

   Solo se completa lo que esta en NULL: un valor que alguien ya ajusto a mano
   desde la pantalla no se pisa. Reejecutar el script no revierte esos ajustes.

   DEBITO QUEDA EN NULL A PROPOSITO: se debita solo y no hay plazo que aplicar.
   Ponerle 0 -"se paga el dia de la factura"- cambiaria la fecha de pago de
   todos esos proveedores.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
SET PLAZO_DIAS = 0
WHERE TIPO = 'PLAZO'
  AND PLAZO_DIAS IS NULL
  AND REPLACE(UPPER(LTRIM(RTRIM(VALOR))), ' ', '') IN ('CONTADO', 'CONTADOEFECTIVO');
GO

/* 'N DIAS', 'N D', o un numero pelado: el numero con el que empieza. Es la
   misma regla del preg_match de plazoEnDias(). */
UPDATE dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
SET PLAZO_DIAS = TRY_CAST(LEFT(LTRIM(VALOR), PATINDEX('%[^0-9]%', LTRIM(VALOR) + 'x') - 1) AS INT)
WHERE TIPO = 'PLAZO'
  AND PLAZO_DIAS IS NULL
  AND LTRIM(VALOR) LIKE '[0-9]%';
GO

/* ----------------------------------------------------------------------------
   5. Control: como quedaron las cinco listas, y si alguna quedo vacia.

   Una lista vacia no es un error -puede que el maestro no tenga ese dato
   cargado en ninguna fila- pero significa que el desplegable de esa columna
   arranca sin opciones y hay que cargarlas a mano desde Parametros.
   ---------------------------------------------------------------------------- */
SELECT TIPO,
       COUNT(*) AS OPCIONES,
       SUM(CASE WHEN VIGENTE = 1 THEN 1 ELSE 0 END) AS VIGENTES,
       SUM(CASE WHEN TIPO = 'PLAZO' AND PLAZO_DIAS IS NULL THEN 1 ELSE 0 END) AS PLAZO_SIN_DIAS
FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_OPCIONES
GROUP BY TIPO
ORDER BY TIPO;
GO

PRINT 'Listas de opciones de Proveedores Locales listas. Se administran en Parametros -> Prov. Locales.';
GO
