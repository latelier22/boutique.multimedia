module.exports = {
  apps: [{
    name: "tablet-socket",
    script: "server.js",
    env: {
      TABLET_SOCKET_PORT: "3010",
      TABLET_SOCKET_TOKEN: "a37fc94e12b85d906ae13cf7582d9b04c26e71fd835ab19ed6402f74aac318e9"
    }
  }]
}
