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

    assert dialogo.is_displayed(), "El formulario no debe cerrarse si faltan campos obligatorios"
    campos_invalidos = dialogo.find_elements(By.CSS_SELECTOR, "input:invalid, select:invalid")
    assert campos_invalidos, "El navegador permitió enviar el formulario con campos obligatorios vacíos"

    mensajes = dialogo.find_elements(By.XPATH, ".//*[normalize-space()='Obligatorio']")
    assert len(mensajes) == 6, (
        "Debe mostrarse 'Obligatorio' junto a cada uno de los seis campos; "
        f"se encontraron {len(mensajes)} mensajes"
    )

    campos_marcados = dialogo.find_elements(
        By.CSS_SELECTOR,
        "[aria-invalid='true'], .border-destructive, .border-red-500, .text-destructive",
    )
    assert len(campos_marcados) >= 6, "Los seis campos obligatorios deben marcarse visualmente en rojo"


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
    abrir_registro(driver)
    completar_obra(driver, "Obra 2")
    registrar(driver)

    mensaje = obtener_notificacion(driver)
    assert mensaje == "Obra ya existente", (
        f"Se esperaba 'Obra ya existente', pero la aplicación mostró {mensaje!r}"
    )
    assert driver.find_element(By.CSS_SELECTOR, "[role='dialog']").is_displayed()
