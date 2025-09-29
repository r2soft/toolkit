#!/bin/bash

GREEN='\033[0;32m' # Verde
RED='\033[0;31m' # Vermelho
NC='\033[0m' # Sem cor (reset)
BLUE='\033[0;34m' # Azul

show_menu() {
    echo "${GREEN}Escolha uma opção:${NC}"
    echo '1) Subir container docker'
    echo '2) Entrar no container'
    echo '3) Derrubar container docker'
    echo '0) Sair'
}

up_container() {
    docker compose up -d
}

enter_container() {
    docker exec -it r2soft-toolkit bash
}

down_container() {
    docker compose down
}

while true; do
    show_menu
    read -p 'Opção Selecionada: ' choice
    case $choice in
        1)
            echo "${BLUE}Subindo container docker...${NC}"
            up_container
            ;;
        2)
            enter_container
            ;;
        3)
            down_container
            ;;
        0)
            echo "${BLUE}Saindo...${NC}"
                exit 0
                ;;
        exit)
            echo "${BLUE}Saindo...${NC}"
                exit 0
                ;;
        *)
            echo "${RED}Opção inválida. Tente novamente.${NC}"
    esac
done
