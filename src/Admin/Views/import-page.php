<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aoe-wrap">
	<div class="aoe-header">
		<h1>Importar Catálogo - <?php echo esc_html( $manufacturer->name ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aoe-catalog-manufacturers' ) ); ?>" class="page-title-action">Volver a Fabricantes</a>
	</div>

	<input type="hidden" id="manufacturer_slug" value="<?php echo esc_attr( $manufacturer->slug ); ?>" data-supported-columns="<?php echo esc_attr( wp_json_encode( isset( $supported_columns ) ? $supported_columns : [] ) ); ?>" />

	<?php if ( 'edac' === $manufacturer->slug ) : ?>
	<div class="aoe-card" id="aoe-structure-card">
		<div class="aoe-step active">
			<div class="aoe-step-title">0. Importar estructura (categorías, subcategorías y series)</div>
			<p>EDAC requiere importar primero la estructura del catálogo (archivo <strong>catalog.csv</strong>).</p>
			<div class="aoe-form-group">
				<label for="csv_structure">Subir archivo de estructura (catalog.csv)</label>
				<input type="file" id="csv_structure" accept=".csv" />
			</div>
			<div id="aoe-structure-status" style="margin-top: 10px;"></div>
		</div>
	</div>
	<?php endif; ?>

	<div class="aoe-card">
		<!-- Step 1: Fuente de Datos -->
		<div class="aoe-step active" id="step-data-source">
			<div class="aoe-step-title">1. Fuente de datos</div>
			<p>Selecciona el origen del catálogo para el fabricante <strong><?php echo esc_html( $manufacturer->name ); ?></strong>:</p>
			
			<div class="aoe-form-group">
				<label for="csv_file">Subir archivo CSV</label>
				<input type="file" id="csv_file" accept=".csv" />
			</div>

			<div style="margin: 15px 0; font-weight: bold; color: #646970;">— O TAMBIÉN —</div>

			<div class="aoe-form-group">
				<label for="csv_paste">Pegar contenido en texto (CSV)</label>
				<textarea id="csv_paste" placeholder="SKU,Name,Category,Description&#10;SAM-001,Connector 1,Connectors,High performance connector&#10;SAM-002,Cable 2,Cables,Coaxial cable"></textarea>
			</div>

			<!-- Dynamic Column Detection / Preview -->
			<div id="aoe-detected-columns" style="margin-top: 20px; display: none;">
				<div style="font-weight: 600; margin-bottom: 8px; font-size: 14px;">Vista previa de datos (primeros 5 registros):</div>
				<div class="aoe-detected-columns" id="aoe-detected-columns-list"></div>
			</div>

			<div class="aoe-btn-row" id="aoe-preview-action" style="margin-top: 15px; display:none; gap: 10px; align-items: center;">
				<button type="button" class="button" id="aoe-btn-test" style="background: #e5f5fa; border-color: #007cba; color: #007cba;">Generar prueba</button>
				<button type="button" class="button button-primary" id="aoe-btn-show-import">Seguir con importación</button>
				<!--<span class="description">Genera una p&aacute;gina temporal con el primer modelo detectado, hasta 200 productos.</span>-->
			</div>
		</div>

		<!-- Step 2: Configuración de Acción y Modo de Importación -->
		<div class="aoe-step" id="aoe-action-step" style="display:none;">
			<div class="aoe-step-title">2. Modo de Importación</div>
			<p>Define cómo se deben insertar los datos en el sistema:</p>

			<div class="aoe-form-group">
				<label style="font-weight: normal; margin-bottom: 8px; display: block;">
					<input type="radio" name="import_mode" value="incremental" checked />
					<strong>Incremental</strong> — Actualiza y añade registros sin eliminar la estructura existente.
				</label>
				<label style="font-weight: normal; display: block;">
					<input type="radio" name="import_mode" value="replace" />
					<strong>Regeneración completa</strong> — Elimina el catálogo actual de este fabricante en la base de datos y lo reconstruye desde cero.
				</label>
			</div>

			<div class="aoe-form-group" style="margin-top:15px;">
				<label style="font-weight:600;">Límite de filas (0 = todas):</label>
				<input type="number" id="aoe-row-limit" value="2000" min="0" step="500" style="width:120px;" />
				<span style="color:#888;font-size:12px;margin-left:8px;">Usa 2000 para validar, luego cambia a 0 para la completa</span>
			</div>
			<div class="aoe-btn-row">				
				<button type="button" class="button button-primary" id="aoe-btn-import">Ejecutar importación</button>
			</div>
		</div>

		<!-- Step 3: Progreso y Logs de Ejecución -->
		<div id="aoe-import-progress" style="display:none; margin-top: 25px; border-top: 1px solid #f0f0f1; padding-top: 20px;">
			<div class="aoe-step-title">3. Progreso de la operación</div>
			
			<div class="aoe-progress-container">
				<div class="aoe-progress-bar" id="aoe-progress-bar"></div>
			</div>
			<div class="aoe-progress-status" id="aoe-progress-text">0 / 0 filas procesadas</div>

			<div style="margin-top: 20px; font-weight: 600;">Consola de procesamiento:</div>
			<pre class="aoe-log-box" id="aoe-log-box" style="display:none;"></pre>
		</div>
	</div>
</div>
