"""Pruebas funcionales del registro de obras en Microsoft Edge."""

from datetime import date, timedelta
from uuid import uuid4

import pytest
from selenium.common.exceptions import TimeoutException
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


LOGIN_URL = "https://acostaalex10.github.io/SCGO/#/login"
TIMEOUT = 15


def iniciar_sesion(driver, sgso_credentials):
    email, password = sgso_credentials
    driver.get(LOGIN_URL)
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "input[type='email']"))).send_keys(email)
    driver.find_element(By.CSS_SELECTOR, "input[type='password']").send_keys(password)
    driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
    wait.until(EC.visibility_of_element_located((By.XPATH, "//a[contains(@href, '#/proyectos')]")))


def abrir_registro(driver):
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.element_to_be_clickable((By.XPATH, "//a[contains(@href, '#/proyectos')]"))).click()
    wait.until(EC.element_to_be_clickable((By.XPATH, "//button[normalize-space()='Nuevo Proyecto']"))).click()
    return wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "[role='dialog']")))


def seleccionar_tipo(driver, tipo="Mantenimiento"):
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, "[role='dialog'] button[role='combobox']"))).click()
    wait.until(EC.element_to_be_clickable((By.XPATH, f"//*[@role='option' and normalize-space()='{tipo}']"))).click()


def establecer_fecha(driver, valor):
    campo = driver.find_element(By.ID, "fecha")
    driver.execute_script(
        """
        const campo = arguments[0], valor = arguments[1];
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
        setter.call(campo, valor);
        campo.dispatchEvent(new Event('input', {bubbles: true}));
        campo.dispatchEvent(new Event('change', {bubbles: true}));
        """,
        campo,
        valor,
    )


def completar_obra(driver, nombre):
    driver.find_element(By.ID, "nombre").send_keys(nombre)
    seleccionar_tipo(driver)
    driver.find_element(By.ID, "encargado").send_keys("Responsable de prueba")
    driver.find_element(By.ID, "ubicacion").send_keys("Posadas, Misiones")
    establecer_fecha(driver, (date.today() + timedelta(days=30)).isoformat())
    driver.find_element(By.ID, "presupuesto").send_keys("100000")


def registrar(driver):
    driver.find_element(By.XPATH, "//button[normalize-space()='Registrar Proyecto']").click()


def obtener_notificacion(driver):
    try:
        notificacion = WebDriverWait(driver, 5, poll_frequency=0.1).until(
            EC.visibility_of_element_located((By.CSS_SELECTOR, "[data-sonner-toast]"))
        )
    except TimeoutException:
        pytest.fail("La aplicación no mostró ninguna notificación después de guardar")
    return notificacion.text.strip()


def esperar_notificacion(driver, texto_esperado):
    """Espera un aviso concreto, aunque todavía quede visible un aviso anterior."""

    def encontrar(_driver):
        avisos = _driver.find_elements(By.CSS_SELECTOR, "[data-sonner-toast]")
        return next(
            (
                aviso.text.strip()
                for aviso in reversed(avisos)
                if aviso.is_displayed() and aviso.text.strip() == texto_esperado
            ),
            False,
        )

    try:
        return WebDriverWait(driver, TIMEOUT, poll_frequency=0.1).until(encontrar)
    except TimeoutException:
        visibles = [
            aviso.text.strip()
            for aviso in driver.find_elements(By.CSS_SELECTOR, "[data-sonner-toast]")
            if aviso.is_displayed()
        ]
        pytest.fail(
            f"Se esperaba la notificación {texto_esperado!r}; "
            f"avisos visibles: {visibles!r}"
        )


def test_registro_exitoso_muestra_mensaje_requerido(driver, sgso_credentials):
    iniciar_sesion(driver, sgso_credentials)
    abrir_registro(driver)
    nombre = f"Prueba Selenium Edge {uuid4().hex[:8]}"
    completar_obra(driver, nombre)
    registrar(driver)

    mensaje = obtener_notificacion(driver)
    assert mensaje == "Obra registrada con éxito", (
        f"Se esperaba 'Obra registrada con éxito', pero la aplicación mostró {mensaje!r}"
    )
    WebDriverWait(driver, TIMEOUT).until(
        EC.visibility_of_element_located((By.XPATH, f"//h4[normalize-space()='{nombre}']"))
    )


def test_campos_obligatorios_se_marcan_y_muestran_obligatorio(driver, sgso_credentials):
    iniciar_sesion(driver, sgso_credentials)
    dialogo = abrir_registro(driver)
    registrar(driver)

    assert dialogo.is_displayed(), (
        "El formulario no debe cerrarse si faltan campos obligatorios"
    )

    # Edge muestra una sola burbuja nativa: la correspondiente al primer
    # campo inválido. Esa burbuja no forma parte del DOM, por lo que Selenium
    # debe leer validationMessage del elemento que recibió el foco.
    campo_enfocado = driver.switch_to.active_element
    mensaje_nativo = driver.execute_script(
        "return arguments[0].validationMessage || '';", campo_enfocado
    ).strip()

    mensajes_inline = [
        elemento
        for elemento in dialogo.find_elements(
            By.XPATH,
            ".//*[normalize-space()='Obligatorio' "
            "or normalize-space()='Campo Obligatorio']",
        )
        if elemento.is_displayed()
    ]
    cantidad_mensajes = len(mensajes_inline) + (1 if mensaje_nativo else 0)

    campos_marcados = dialogo.find_elements(
        By.CSS_SELECTOR,
        "[aria-invalid='true'], .border-destructive, .border-red-500",
    )
    campos_marcados_visibles = [
        elemento for elemento in campos_marcados if elemento.is_displayed()
    ]

    errores = []
    if cantidad_mensajes != 6:
        errores.append(
            "deben mostrarse seis mensajes de campo obligatorio, "
            f"pero se encontraron {cantidad_mensajes}"
        )
    if len(campos_marcados_visibles) < 6:
        errores.append(
            "los seis campos deben quedar marcados visualmente en rojo, "
            f"pero se encontraron {len(campos_marcados_visibles)}"
        )

    assert not errores, "; ".join(errores)


def test_cancelar_solicita_confirmacion(driver, sgso_credentials):
    iniciar_sesion(driver, sgso_credentials)
    abrir_registro(driver)
    driver.find_element(By.ID, "nombre").send_keys("Obra que se cancelará")
    driver.find_element(By.XPATH, "//button[normalize-space()='Cancelar']").click()

    texto_esperado = "Confirmar cancelación de registro de obra"
    try:
        alerta = WebDriverWait(driver, 3).until(EC.alert_is_present())
        assert alerta.text == texto_esperado
        alerta.dismiss()
    except TimeoutException:
        confirmaciones = driver.find_elements(By.XPATH, f"//*[normalize-space()='{texto_esperado}']")
        if not confirmaciones:
            pytest.fail(
                "Al pulsar Cancelar, el formulario se cerró sin mostrar "
                f"{texto_esperado!r}"
            )
        assert confirmaciones[0].is_displayed()


def test_obra_duplicada_muestra_mensaje(driver, sgso_credentials):
    iniciar_sesion(driver, sgso_credentials)
    nombre = f"Obra duplicada Selenium {uuid4().hex[:8]}"

    # La primera alta prepara el dato dentro del propio caso de prueba.
    abrir_registro(driver)
    completar_obra(driver, nombre)
    registrar(driver)
    WebDriverWait(driver, TIMEOUT).until(
        EC.invisibility_of_element_located((By.CSS_SELECTOR, "[role='dialog']"))
    )
    # La segunda alta repite exactamente nombre y ubicación, que forman el duplicado.
    abrir_registro(driver)
    completar_obra(driver, nombre)
    registrar(driver)

    mensaje = esperar_notificacion(driver, "Obra ya existente")
    assert mensaje == "Obra ya existente"
    assert driver.find_element(By.CSS_SELECTOR, "[role='dialog']").is_displayed()
