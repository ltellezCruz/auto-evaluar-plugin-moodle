"""
pdf.py
Conversión de un PDF a imágenes, una por página.
"""

import os


def convertir_pdf_a_imagenes(ruta_pdf: str, dpi: int, max_paginas: int) -> list[str]:
    """Convierte CADA página del PDF en una imagen PNG separada.
    Rechaza el PDF antes de convertir si tiene más de max_paginas: cada
    página es una llamada a Gemini y una imagen en disco."""
    import pymupdf

    carpeta_salida = os.path.join(os.path.dirname(ruta_pdf), "paginas")
    os.makedirs(carpeta_salida, exist_ok=True)

    nombre_base = os.path.splitext(os.path.basename(ruta_pdf))[0]
    zoom = dpi / 72
    matriz = pymupdf.Matrix(zoom, zoom)

    rutas_imagenes = []
    documento = pymupdf.open(ruta_pdf)
    if len(documento) > max_paginas:
        total = len(documento)
        documento.close()
        raise ValueError(f"El PDF tiene {total} páginas; el máximo es {max_paginas} (MAX_PAGES).")
    for numero_pagina in range(len(documento)):
        pagina = documento[numero_pagina]
        pixmap = pagina.get_pixmap(matrix=matriz)
        ruta_imagen = os.path.join(carpeta_salida, f"{nombre_base}_pag{numero_pagina + 1}.png")
        pixmap.save(ruta_imagen)
        rutas_imagenes.append(ruta_imagen)
    documento.close()

    return rutas_imagenes
