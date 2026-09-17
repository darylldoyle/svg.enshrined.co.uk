<?php
ini_set( 'xdebug.var_display_max_children', - 1 );
ini_set( 'xdebug.var_display_max_data', - 1 );
ini_set( 'xdebug.var_display_max_depth', - 1 );

require 'vendor/autoload.php';

// Get the installed version from composer.lock
function getInstalledVersion( $packageName ) {
	$composerLockFile = file_get_contents( 'composer.lock' );
	$composerLock     = json_decode( $composerLockFile, true );

	foreach ( $composerLock['packages'] as $package ) {
		if ( $package['name'] === $packageName ) {
			return $package['version'];
		}
	}

	return 'Unknown';
}

if ( isset( $_POST['dirty'] ) ) {

	$dirty = $_POST['dirty'];

	$sanitizer = new enshrined\svgSanitize\Sanitizer();
	$sanitizer->removeRemoteReferences( true );
	$sanitizer->minify( false );

	try {
		$clean = $sanitizer->sanitize( $dirty );
	} catch ( Exception $e ) {
		$errors = $e->getMessage();
	}

} else {
	$dirty = file_get_contents( 'initial.svg' );
}

// Get the installed version of svg-sanitize
$svgSanitizeVersion = getInstalledVersion( 'enshrined/svg-sanitize' );
?>
<html>
<head>
    <title>SVG Sanitizer Test</title>
    <style type="text/css">
        .wrap {
            width: 100%;
            max-width: 60em;
            margin: 2em auto;
        }

        label {
            display: block;
            width: 100%;
            margin: 2em 0 .5em 0;
            font-weight: bold;
        }

        textarea {
            width: 100%;
            display: block;
            height: 20em;
            font-family: monospace, fixed;
            font-size: .8em;
            line-height: 1.5em;
        }

        input[type="submit"] {
            width: 10em;
            margin: 2em 0;
            background: #16b400;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="heading">
        <h1>SVG Sanitizer Test</h1>

        <p>Currently running version <code><?php echo htmlspecialchars( $svgSanitizeVersion ); ?></code></p>

        <p>This is here to allow you to test the SVG sanitizer. If you find anything that makes it through, please
            either open an issue on the
            <a href="https://github.com/darylldoyle/svg-sanitizer" target="_blank">Github repo</a>
            or email it to me!</p>

        <p> Have fun!</p>
    </div>

	<?php
	if ( isset( $errors ) ) {
		echo $errors;
	}
	?>

    <form action="" method="post">

        <label for="dirty">Dirty SVG</label>
        <textarea name="dirty" id="dirty"><?php
			if ( isset( $dirty ) ) {
				echo htmlspecialchars( $dirty );
			}
			?></textarea>
        <input type="submit" value="Sanitize">

        <label>Cleaned SVG</label>
        <textarea><?php
			if ( isset( $clean ) ) {
				echo $clean;
			}
			?></textarea>

        <div class="wrap" style="width: 100%;border: 1px solid black;">
			<?php
			if ( isset( $clean ) ) {
				echo $clean;
			}
			?>
        </div>

    </form>

</div>
</body>
</html>
