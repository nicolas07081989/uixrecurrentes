<?php
    function url(){
        $url = str_replace(array('https://','http://'),'',get_bloginfo('wpurl'));
        $len = strpos($url, '/');
        return substr($url,0,$len);
    } 
    function get_client_ip() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    } 
    function testing_page_handler()
    {
    ?>
    <script type="text/javascript" defer> 
        function testing(enviroment,enviromentName){  
            let templateUrl = '<?= get_site_url(); ?>'; 
            logFetch(templateUrl+'/wp-json/datafast/testConection?pro='+enviroment).then(response=>{
                let isProd= (enviroment=='2'||enviroment=='3');
                let idResult=('result'+enviroment);
                let enviromentText = (isProd)?'Producción '+enviromentName:'Pruebas '+enviromentName;
                let resultJson = JSON.parse(JSON.parse(response,true),true);
                let isError = resultJson.error!=null;
                let textByType = isError?`Error al Conectarse con el API de ${enviromentText} del botón de pago. Error: `:
                `Conexión exitosa con el API ${enviromentText}.`;
                let isWarning = isError?false:resultJson.result.code!="000.200.100";
                if(isWarning) textByType += " Con una Advertencia: ";
                let text = isError? resultJson.error:isWarning?resultJson.result.description:'';
                let type=isError?'error':isWarning?'warning':'success';
                jQuery('#'+idResult).html(`
                <div class="notice notice-${type} is-dismissible">
                    <p>
                        <strong>${textByType}</strong>
                        ${text}
                    </p>
                    <button type="button" class="notice-dismiss" onClick="jQuery('#${idResult}').html('')">
                        <span class="screen-reader-text">Descartar este aviso.</span>
                        </button>
                </div>`); 
            }); 
        }
        async function logFetch(url) {
            try {
            const response = await fetch(url, {
                method: 'GET' 
            });
            return await response.text();
            }
            catch (err) {
            alert('Ocurrio un error cuando se intento hacer el Test.');
            console.log('error', err);
            }
        }
    </script>
    <tbody > 
	<div class="formdatabc">		
	<style>
        input[type=text],input[type=number], select, textarea{
            width: 60%; 
            border: 1px solid #ccc; 
            box-sizing: border-box;
            resize: vertical;
            margin: 5px 15px 2px;
            padding: 1px 12px;
        } 
        .dfa{
            width: 40%; 
            margin: 5px 15px 2px !important;
            padding: 1px 12px !important;
        }
        .dflb{
            margin: 5px 15px 2px;
            padding: 1px 12px;
        }
    </style>	  
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPServidor"><?php _e('Ip Pública del Servidor:')?></label>
                <br>
                <input disabled id="IPServidor" name="IPServidor" type="text" value="<?php echo gethostbyname(url()) ?>"
                        required>
            </p> 
        </div>     
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPCliente"><?php _e('Ip cliente (Navegador Actual):')?></label>
                <br>
                <input disabled id="IPCliente" name="IPCliente" type="text" value="<?php echo get_client_ip() ?>"
                        required>
            </p> 
        </div> 
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPCliente"><?php _e('Conexión a Pruebas (test.oppwa.com):')?></label>
                <br> 
                <a class="button button-primary button-hero load-customize hide-if-no-customize dfa" 
                href="javascript:testing(0,'(test.oppwa.com)')">Probar Conexión al Ambiente de pruebas (test.oppwa.com)</a> 
                <div id='result0'>
                    
                </div>
            </p> 
        </div> 
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPCliente"><?php _e('Conexión a Pruebas (eu-test.oppwa.com):')?></label>
                <br> 
                <a class="button button-primary button-hero load-customize hide-if-no-customize dfa" 
                href="javascript:testing(1,'(eu-test.oppwa.com)')">Probar Conexión al Ambiente de pruebas (eu-test.oppwa.com)</a> 
                <div id='result1'>
                    
                </div>
            </p> 
        </div> 
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPCliente"><?php _e('Conexión a  (oppwa.com):')?></label>
                <br> 
                <a class="button button-primary button-hero load-customize hide-if-no-customize dfa" 
                href="javascript:testing(2,'(oppwa.com)')">Probar Conexión al Ambiente de Producción (oppwa.com)</a> 
                <div id='result2'>
                    
                </div>
            </p> 
        </div> 
        <div class="form2bc">
            <p>			
                <label class="dflb" for="IPCliente"><?php _e('Conexión a Producción (eu-prod.oppwa.com):')?></label>
                <br> 
                <a class="button button-primary button-hero load-customize hide-if-no-customize dfa" 
                href="javascript:testing(3,'(eu-prod.oppwa.com)')">Probar Conexión al Ambiente de Producción (eu-prod.oppwa.com)</a> 
                <div id='result3'>
                    
                </div>
            </p> 
        </div> 
         
    </div>
</tbody> 
<?php
}