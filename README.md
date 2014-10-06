cent-php
========

Php library to communicate with Centrifuge
		
		Using with composer
		add dependency at your composer.json
		
		"sl4mmer/phpcent":"dev-master",

		


        $client = new \phpcent\CentrifugeClient("Your Host");
        $client->setProject("Project Id","ProjectKey");
        $client->send($method,$params)
        //Publish method has a shortend
        $client->publish($channelName,$data);
        


        

