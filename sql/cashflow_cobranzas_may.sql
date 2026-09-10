# Script SQL para Cobranzas Mayoristas en Cashflow
IF NOT EXISTS (SELECT * FROM RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = 'cobranzas_may_dias_vto')
BEGIN
    INSERT INTO RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO, FECHA_UPDATE, USUARIO)
    VALUES ('cobranzas_may_dias_vto', '60', 'INT', 'Días de plazo a sumar a la fecha de emisión para proyectar cobranza mayorista', 'COBRANZAS', 'GENERAL', GETDATE(), 'SISTEMA');
END
