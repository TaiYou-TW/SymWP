FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN sudo dpkg --add-architecture i386 && \
    apt-get update && \
    apt-get install -y curl && \
    install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
         https://download.docker.com/linux/ubuntu \
         $(. /etc/os-release && echo ${UBUNTU_CODENAME:-focal}) stable" \
         > /etc/apt/sources.list.d/docker.list

RUN apt-get update && \
    apt-get install -y sudo gnupg ca-certificates passwd linux-image-generic \
                        make build-essential jq wget p7zip-rar libglib2.0-dev \
                        libsdl1.2debian libcapstone-dev rsync cmake \
                        cloud-image-utils docker-ce docker-ce-cli libbsd-dev \
                        docker-buildx-plugin docker-compose-plugin unzip \
                        libboost-all-dev rapidjson-dev libelf-dev libzstd-dev \
                        libgtest-dev libgmock-dev libdwarf-dev libmemcached-dev \
                        libpixman-1-dev libpng-dev nasm gcc-multilib g++-multilib \
                        libelf-dev:i386 g++-mingw-w64 gcc-mingw-w64 libxml2-dev \
                        libsqlite3-dev && \
    useradd -m -s /bin/bash s2e && \
    echo 's2e:s2e' | chpasswd && \
    usermod -aG sudo s2e && \
    usermod -aG docker s2e && \
    groupadd -g 108 kvm || true && usermod -aG kvm s2e && \
    chmod ugo+r /boot/vmlinu*

USER s2e

CMD ["/bin/bash"]
