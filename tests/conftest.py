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
                folder = Path("screenshots")
                folder.mkdir(exist_ok=True)
                stamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
                name = "".join(c if c.isalnum() or c in "-_" else "_" for c in request.node.name)
                try:
                    browser.save_screenshot(str(folder / f"{name}_{stamp}.png"))
                except WebDriverException as exc:
                    warnings.warn(f"No se pudo guardar la captura: {type(exc).__name__}")
        finally:
            browser.quit()
            shutil.rmtree(profile, ignore_errors=True)
