"""Configuración de las pruebas de navegador en Microsoft Edge."""

import os
import shutil
import tempfile
import warnings
from datetime import datetime
from pathlib import Path

import pytest
from selenium import webdriver
from selenium.common.exceptions import WebDriverException
from selenium.webdriver.edge.service import Service


def _nombre_seguro(valor, maximo=90):
    """Convierte el nombre del caso o del paso en un nombre de archivo estable."""

    limpio = "".join(
        caracter if caracter.isalnum() or caracter in "-_" else "_"
        for caracter in valor
    ).strip("_")
    return (limpio or "sin_nombre")[:maximo]


class EvidenciaPasos:
    """Captura el estado visible inmediatamente antes de cada clic."""

    def __init__(self, browser, nombre_test):
        self.browser = browser
        self.nombre_test = _nombre_seguro(nombre_test)
        self.numero_paso = 0
        self.carpeta = Path("screenshots") / "pasos" / self.nombre_test
        self.carpeta.mkdir(parents=True, exist_ok=True)

    def capturar(self, descripcion, elemento=None):
        self.numero_paso += 1
        nombre_paso = _nombre_seguro(descripcion)
        archivo = self.carpeta / (
            f"{self.nombre_test}__paso_{self.numero_paso:02d}__{nombre_paso}.png"
        )

        borde_anterior = None
        if elemento is not None:
            try:
                self.browser.execute_script(
                    "arguments[0].scrollIntoView({block: 'center', inline: 'center'});",
                    elemento,
                )
                borde_anterior = self.browser.execute_script(
                    "const anterior = arguments[0].style.outline; "
                    "arguments[0].style.outline = '4px solid #e11d48'; "
                    "return anterior;",
                    elemento,
                )
            except WebDriverException:
                borde_anterior = None

        try:
            if not self.browser.save_screenshot(str(archivo)):
                warnings.warn(f"Edge no pudo guardar la captura {archivo}")
        except WebDriverException as exc:
            warnings.warn(
                f"No se pudo guardar la captura del paso {self.numero_paso}: "
                f"{type(exc).__name__}"
            )
        finally:
            if elemento is not None:
                try:
                    self.browser.execute_script(
                        "arguments[0].style.outline = arguments[1] || '';",
                        elemento,
                        borde_anterior,
                    )
                except WebDriverException:
                    pass

        return archivo

    def click(self, elemento, descripcion):
        """Toma la evidencia y sólo después ejecuta el clic solicitado."""

        self.capturar(descripcion, elemento)
        elemento.click()


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    report = outcome.get_result()
    setattr(item, f"rep_{report.when}", report)


@pytest.fixture
def sgso_credentials():
    email = os.getenv("SGSO_EMAIL", "administrativo@sgso.test")
    password = os.getenv("SGSO_PASSWORD", "admin123")
    if not password:
        raise pytest.UsageError("Definí SGSO_PASSWORD antes de ejecutar Selenium (ver readme.md).")
    return email, password


@pytest.fixture
def driver(request):
    profiles = Path(".edge-profiles").resolve()
    profiles.mkdir(exist_ok=True)
    profile = Path(tempfile.mkdtemp(prefix="selenium-", dir=profiles))

    options = webdriver.EdgeOptions()
    options.add_argument("--start-maximized")
    options.add_argument(f"--user-data-dir={profile}")
    options.add_argument("--no-first-run")
    options.add_argument("--no-default-browser-check")
    options.add_argument("--disable-crash-reporter")
    if os.getenv("HEADLESS") == "1":
        options.add_argument("--headless=new")
        options.add_argument("--window-size=1440,1000")

    # Selenium Manager obtiene el controlador si no se indicó uno manualmente.
    driver_path = os.getenv("EDGE_DRIVER_PATH")
    service = Service(executable_path=driver_path) if driver_path else Service()
    browser = webdriver.Edge(options=options, service=service)
    browser.evidencia = EvidenciaPasos(browser, request.node.name)
    browser.set_page_load_timeout(60)
    try:
        yield browser
    finally:
        try:
            failed = any(
                getattr(request.node, f"rep_{phase}", None)
                and getattr(request.node, f"rep_{phase}").failed
                for phase in ("setup", "call")
            )
            if failed:
                folder = Path("screenshots") / "fallos"
                folder.mkdir(parents=True, exist_ok=True)
                stamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
                name = _nombre_seguro(request.node.name)
                try:
                    browser.save_screenshot(
                        str(folder / f"{name}__fallo__{stamp}.png")
                    )
                except WebDriverException as exc:
                    warnings.warn(f"No se pudo guardar la captura: {type(exc).__name__}")
        finally:
            browser.quit()
            shutil.rmtree(profile, ignore_errors=True)
