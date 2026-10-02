"""Pruebas funcionales de carga de materiales y registro de consumo."""

import os
import re

import pytest
from selenium.common.exceptions import TimeoutException
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


LOGIN_URL = "https://acostaalex10.github.io/SCGO/#/login"
TIMEOUT = 15
OBRA = "Obra Vial Ruta 14"
MATERIAL = "Cemento"


@pytest.fixture
def credenciales_encargado():
    return (
        os.getenv("SGSO_TECNICO_EMAIL", "tecnico@sgso.test"),
        os.getenv("SGSO_TECNICO_PASSWORD", "tecnico123"),
    )


def iniciar_sesion(driver, credenciales):
    email, password = credenciales
    driver.get(LOGIN_URL)
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "input[type='email']"))).send_keys(email)
    driver.find_element(By.CSS_SELECTOR, "input[type='password']").send_keys(password)
    driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
    wait.until(EC.visibility_of_element_located((By.XPATH, "//a[contains(@href, '#/materiales')]")))


def abrir_materiales(driver, obra=OBRA):
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.element_to_be_clickable((By.XPATH, "//a[contains(@href, '#/materiales')]"))).click()
    wait.until(EC.url_contains("#/materiales"))
    selector = wait.until(
        EC.element_to_be_clickable((By.XPATH, "//button[@role='combobox' and .//*[contains(., 'Elegí una obra')]]"))
    )
    selector.click()
    wait.until(
        EC.element_to_be_clickable((By.XPATH, f"//*[@role='option' and normalize-space()='{obra}']"))
    ).click()
    wait.until(EC.visibility_of_element_located((By.XPATH, "//*[normalize-space()='Materiales de la obra']")))
    wait.until(
        lambda navegador: navegador.find_elements(By.CSS_SELECTOR, "input[type='number']")
        or False,
        message=f"La lista de materiales de {obra!r} no terminó de cargar",
    )


def tarjeta_material(driver, nombre=MATERIAL):
    return WebDriverWait(driver, TIMEOUT).until(
        EC.visibility_of_element_located(
            (
                By.XPATH,
                f"//*[normalize-space()='{nombre}']"
                "/ancestor::div[.//input[@type='number'] "
                "and .//button[normalize-space()='Registrar consumo']][1]",
            )
        ),
        message=f"No se encontró la tarjeta del material {nombre!r}",
    )


def numero(texto):
    return float(texto.replace(".", "").replace(",", "."))


def estado_material(driver, nombre=MATERIAL):
    texto = tarjeta_material(driver, nombre).text.replace("\n", " ")
    patron = rf"{re.escape(nombre)}\s+([\d.,]+)\s*/\s*([\d.,]+).*?restante\s+(-?[\d.,]+)"
    coincidencia = re.search(patron, texto)
    assert coincidencia, f"No se pudo interpretar el stock mostrado para {nombre!r}: {texto!r}"
    return tuple(numero(valor) for valor in coincidencia.groups())


def registrar_consumo(driver, cantidad, nombre=MATERIAL):
    tarjeta = tarjeta_material(driver, nombre)
    campo = tarjeta.find_element(By.CSS_SELECTOR, "input[type='number']")
    campo.clear()
    campo.send_keys(str(cantidad))
    tarjeta.find_element(By.XPATH, ".//button[normalize-space()='Registrar consumo']").click()


def obtener_notificacion(driver):
    try:
        toast = WebDriverWait(driver, 5, poll_frequency=0.1).until(
            EC.visibility_of_element_located((By.CSS_SELECTOR, "[data-sonner-toast]"))
        )
    except TimeoutException:
        pytest.fail("El sistema no mostró ninguna notificación sobre la operación de materiales")
    return toast.text.strip()


def test_registro_consumo_exitoso(driver, credenciales_encargado):
    iniciar_sesion(driver, credenciales_encargado)
    abrir_materiales(driver)
    consumido_antes, asignado, restante = estado_material(driver)
    assert restante >= 1, "El material elegido no tiene disponibilidad para probar el flujo exitoso"

    registrar_consumo(driver, 1)
    mensaje = obtener_notificacion(driver)
    assert mensaje == "Consumo registrado", f"Notificación inesperada: {mensaje!r}"
    WebDriverWait(driver, TIMEOUT).until(
        lambda navegador: estado_material(navegador)[0] == consumido_antes + 1
    )
    consumido_despues, asignado_despues, _ = estado_material(driver)
    assert asignado_despues == asignado
    assert consumido_despues == consumido_antes + 1


def test_lista_consumos_se_guarda_y_envia_a_planificacion(driver, credenciales_encargado):
    iniciar_sesion(driver, credenciales_encargado)
    abrir_materiales(driver)
    campos = WebDriverWait(driver, TIMEOUT).until(
        lambda navegador: elementos
        if len(elementos := navegador.find_elements(By.CSS_SELECTOR, "input[type='number']")) >= 2
        else False,
        message="Se esperaban varios materiales para conformar la lista de consumos",
    )
    campos[0].send_keys("1")
    campos[1].send_keys("1")

    botones = driver.find_elements(
        By.XPATH,
        "//button[contains(translate(normalize-space(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', "
        "'abcdefghijklmnopqrstuvwxyz'), 'guardar') and "
        "contains(translate(normalize-space(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', "
        "'abcdefghijklmnopqrstuvwxyz'), 'enviar')]",
    )
    if not botones:
        pytest.fail("No existe una acción para guardar y enviar la lista de materiales a planificación")
    botones[0].click()

    mensaje = obtener_notificacion(driver)
    assert "planific" in mensaje.lower() or "enviad" in mensaje.lower(), (
        f"La aplicación no confirmó el envío de la lista a planificación: {mensaje!r}"
    )


def test_inicio_sesion_fallido_del_encargado(driver, credenciales_encargado):
    email, _ = credenciales_encargado
    driver.get(LOGIN_URL)
    wait = WebDriverWait(driver, TIMEOUT)
    wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "input[type='email']"))).send_keys(email)
    driver.find_element(By.CSS_SELECTOR, "input[type='password']").send_keys("contrasena-incorrecta")
    driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()

    error = wait.until(EC.visibility_of_element_located((By.XPATH, "//*[normalize-space()='Credenciales invalidas']")))
    assert error.is_displayed()
    assert "#/login" in driver.current_url


def test_encargado_sale_hacia_otro_apartado(driver, credenciales_encargado):
    iniciar_sesion(driver, credenciales_encargado)
    abrir_materiales(driver)
    driver.find_element(By.XPATH, "//a[contains(@href, '#/proyectos')]").click()

    WebDriverWait(driver, TIMEOUT).until(EC.url_contains("#/proyectos"))
    assert driver.find_element(By.XPATH, "//h2[normalize-space()='Gestión de Proyectos']").is_displayed()


def test_material_sin_stock_informa_falta_disponibilidad(driver, credenciales_encargado):
    iniciar_sesion(driver, credenciales_encargado)
    abrir_materiales(driver)
    consumido_antes, _, restante = estado_material(driver)

    registrar_consumo(driver, restante + 1)
    mensaje = obtener_notificacion(driver)
    consumido_despues, _, _ = estado_material(driver)

    mensajes_aceptados = ("stock insuficiente", "sin stock", "falta de disponibilidad", "no disponible")
    assert any(texto in mensaje.lower() for texto in mensajes_aceptados), (
        "El sistema debía informar falta de disponibilidad; "
        f"mostró {mensaje!r} y el consumo cambió de {consumido_antes:g} a {consumido_despues:g}"
    )
    assert consumido_despues == consumido_antes, "El consumo cambió aunque no había stock suficiente"


def test_cancelar_descarta_cambios_sin_guardar(driver, credenciales_encargado):
    iniciar_sesion(driver, credenciales_encargado)
    abrir_materiales(driver)
    consumido_antes, _, _ = estado_material(driver)
    tarjeta = tarjeta_material(driver)
    tarjeta.find_element(By.CSS_SELECTOR, "input[type='number']").send_keys("10")

    botones_cancelar = driver.find_elements(By.XPATH, "//button[normalize-space()='Cancelar']")
    if not botones_cancelar:
        pytest.fail("La sección de materiales no ofrece una acción Cancelar para descartar la lista")
    botones_cancelar[0].click()

    consumido_despues, _, _ = estado_material(driver)
    assert consumido_despues == consumido_antes, "Cancelar modificó el consumo del material"
    assert tarjeta_material(driver).find_element(By.CSS_SELECTOR, "input[type='number']").get_attribute("value") == ""
