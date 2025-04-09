Comando pra rodar o script:

php simuladorFilaV2.php --queues="2,3,1,4,3,4|1,5,,,2,3" --random=0.8,0.7,0.8,0.6,0.2,0.5

formato do parametro queues:

servers,capacity,arrival_min,arrival_max,service_min,service_max|...

Se não passar o parametro random, ele vai gerar 100000 números pseudoaleatórios
