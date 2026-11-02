"""
pdf.py
Conversión de un PDF a imágenes, una por página.
"""

import os


def convertir_pdf_a_imagenes(ruta_pdf: str, dpi: int) -> list[str]:
    """Convierte CADA página del PDF en una imagen PNG separada."""
    import pymupdf

    carpeta_salida = os.path.join(os.path.dirname(ruta_pdf), "paginas")
    os.makedirs(carpeta_salida, exist_ok=True)

    nombre_base = os.path.splitext(os.path.basename(ruta_pdf))[0]
    zoom = dpi / 72
    matriz = pymupdf.Matrix(zoom, zoom)

    rutas_imagenes = []
    documento = pymupdf.open(ruta_pdf)
    for numero_pagina in range(len(documento)):
        pagina = documento[numero_pagina]
        pixmap = pagina.get_pixmap(matrix=matriz)
        ruta_imagen = os.path.join(carpeta_salida, f"{nombre_base}_pag{numero_pagina + 1}.png")
        pixmap.save(ruta_imagen)
        rutas_imagenes.append(ruta_imagen)
    documento.close()

    return rutas_imagenes
